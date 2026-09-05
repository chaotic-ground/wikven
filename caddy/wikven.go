// Package wikvencaddy registers the "build", "serve" and "translate" subcommands on the wikven
// binary.
package wikvencaddy

import (
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"runtime/debug"
	"strings"

	caddycmd "github.com/caddyserver/caddy/v2/cmd"
)

func init() {
	caddycmd.RegisterCommand(caddycmd.Command{
		Name:  "build",
		Usage: " ",
		Short: "Build the static site from the embedded MediaWiki",
		Long: "Builds WIKVEN_WORKDIR/src into WIKVEN_WORKDIR/dist " +
			"(WIKVEN_WORKDIR defaults to the current directory).",
		Func: func(_ caddycmd.Flags) (int, error) {
			return reexec("php-cli", "build.php")
		},
	})

	serveFlags := flag.NewFlagSet("serve", flag.ExitOnError)
	serveFlags.String("listen", ":8080", "the address to listen on")
	caddycmd.RegisterCommand(caddycmd.Command{
		Name:  "serve",
		Usage: "[--listen <addr>]",
		Short: "Serve the built static site for local preview",
		Long: "Serves WIKVEN_WORKDIR/dist (WIKVEN_WORKDIR defaults to the current " +
			"directory) over HTTP, on :8080 by default, for local preview.",
		Flags: serveFlags,
		Func: func(fl caddycmd.Flags) (int, error) {
			workdir := os.Getenv("WIKVEN_WORKDIR")
			if workdir == "" {
				workdir = "."
			}
			// Through a generated Caddyfile rather than `file-server`, which serves file names and
			// nothing else. See previewConfig() for what a static host answers that it does not.
			return reexecStdin(
				strings.NewReader(previewConfig(filepath.Join(workdir, "dist"), fl.String("listen"))),
				"run", "--config", "-", "--adapter", "caddyfile")
		},
	})

	// The flags the four helpers take, declared so this command accepts them: Caddy hands the set
	// to cobra, which rejects a flag no command declares. They are not read here -- what reaches
	// translate.php is the tail of the command line as the caller wrote it, so a helper's own
	// option parsing is the only one there is, and a file name is passed through beside them.
	//
	// --source is deliberately absent. The helpers take one; here it always names the working
	// directory's own source tree, exactly as the Docker entry point points them at the mounted
	// one, so a run means the same thing on either product.
	translateFlags := flag.NewFlagSet("translate", flag.ExitOnError)
	translateFlags.Bool("all", false, "every translatable page under the source directory")
	translateFlags.Bool("gate", false, "check: exit non-zero on a source page that cannot be translated")
	translateFlags.String("path-prefix", "", "check: prefix reported file names with this")
	caddycmd.RegisterCommand(caddycmd.Command{
		Name:  "translate",
		Usage: "<mark|scaffold|check|stamp> [<language>] [<file>|--all]",
		Short: "Mark, scaffold, stamp or check the translations in a source tree",
		Long: "Runs one of the translation helpers over WIKVEN_WORKDIR/src (WIKVEN_WORKDIR " +
			"defaults to the current directory) and exits without building anything. mark, " +
			"scaffold and stamp write into the source tree; check only reads it.",
		Flags: translateFlags,
		Func: func(_ caddycmd.Flags) (int, error) {
			return reexec(append([]string{"php-cli", "translate.php"}, commandTail("translate")...)...)
		},
	})
}

// commandTail is everything the caller wrote after the named subcommand, verbatim.
//
// Verbatim because the helpers parse it themselves: their options and a file name arrive in one
// list, and rebuilding that list from parsed flags would mean this file deciding what a helper
// accepts -- a second, staler copy of the maintenance scripts' own option sets.
// Scanning from argv[1], never argv[0]: the executable's own path is not one of the caller's
// words, and a binary installed under the command's name would otherwise match itself and hand
// the command word back as its own first argument.
func commandTail(name string) []string {
	for i := 1; i < len(os.Args); i++ {
		if os.Args[i] == name {
			return os.Args[i+1:]
		}
	}
	return nil
}

// reexec runs this same binary with the given args, wiring stdio and the current environment.
// The Caddyfile the preview runs. The one line in it that is not "serve this directory" is
// try_files, which is what makes a preview answer the addresses a published site answers.
//
// Measured against GitHub Pages serving this project's own documentation:
//
//	/Development      200, the same bytes as /Development.html (same ETag)
//	/Development/     404 -- the trailing-slash form of a page is not served
//	/Configuration    200, Configuration.html, though a Configuration/ directory sits beside it
//	/citizen          a redirect to /citizen/, because that directory has an index.html
//	/assets/          404 -- a directory without an index is not listed
//	/Nope             404
//
// The candidate order says the first three: ".html first" is what answers /Configuration with the
// page rather than with the directory beside it. file_server answers the rest on its own, and its
// redirect is a 308 where the host sends a 301 -- the same instruction to a reader, and the one
// place this deliberately does not chase a host's exact answer.
//
// Netlify and Cloudflare Pages answer the extension-less form the same way, so this aims at what
// static hosts have in common rather than at one host's full rule set.
func previewConfig(root, listen string) string {
	// The site address is the port alone, so the preview answers whatever Host a reader's browser
	// sends -- localhost, 127.0.0.1, a container's name. An address naming an interface keeps it,
	// as the flag's own documentation promises, through bind rather than through host matching.
	host, port, err := net.SplitHostPort(listen)
	if err != nil {
		host, port = "", strings.TrimPrefix(listen, ":")
	}
	bind := ""
	if host != "" {
		bind = "\n\tbind " + caddyToken(host)
	}
	return fmt.Sprintf(`{
	admin off
	auto_https off
}

:%s {%s
	root * %s
	try_files {path}.html {path}
	encode zstd gzip
	file_server
}
`, port, bind, caddyToken(root))
}

// A value written where a Caddyfile expects one token: quoted, since a path may hold a space.
func caddyToken(value string) string {
	return `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`).Replace(value) + `"`
}

func reexec(args ...string) (int, error) {
	return reexecStdin(os.Stdin, args...)
}

func reexecStdin(stdin io.Reader, args ...string) (int, error) {
	self, err := os.Executable()
	if err != nil {
		return 1, err
	}
	cmd := exec.Command(self, args...)
	cmd.Stdin = stdin
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	cmd.Env = append(os.Environ(), runtimeEnv()...)
	if err := cmd.Run(); err != nil {
		// Propagate the child's own exit code, so a caller scripting on `wikven build` -- a
		// Makefile, a CI job, a deploy script -- can tell a refusal from a success.
		//
		// By exiting here rather than returning the code, because returning it does not work for
		// the code that matters most. Caddy's cobra wrapper turns a CommandFunc's status into a
		// process exit status only when it is greater than one:
		//
		//     status, err := f(Flags{cmd.Flags()})
		//     if status > 1 { ... return &exitError{ExitCode: status, Err: err} }
		//     return err
		//
		// so returning (1, nil) -- an ordinary failed build, which is every failed build -- exits
		// 0. Returning (1, err) would exit 1 but print Caddy's own error line on top of the
		// diagnostic the child already wrote to the stderr it shares with us. The child has
		// finished and nothing else here has anything left to do, so exiting is the honest answer.
		//
		// A signalled child reports -1; call that 1.
		var exit *exec.ExitError
		if errors.As(err, &exit) {
			if code := exit.ExitCode(); code > 0 {
				os.Exit(code)
			}
			os.Exit(1)
		}
		return 1, err
	}
	return 0, nil
}

// The name of the environment variable naming the server this binary is, and the separator between
// its entries. build.php reads it to name FrankenPHP and Caddy on the licenses page it writes,
// beside MediaWiki and PHP: a site baked with the binary was baked by these, and an export has no
// Special:Version to ask.
const (
	runtimeVar = "WIKVEN_RUNTIME"
	runtimeSep = ";"
)

// The server this binary is, as "<name> <version>" entries, or nothing where the build did not
// link one.
//
// Read out of the build rather than written down, the same way the licenses page reads MediaWiki's
// license from core's own composer.json: xcaddy settles these versions when it assembles the
// binary, and a copy here would be a second place for them to drift from what was actually linked.
//
// FrankenPHP is matched by the last element of its module path, because that path has moved
// between organisations (dunglas, then php) while the module stayed the one thing being asked
// about. Caddy is matched whole: "caddy" alone would also match the several Caddy modules the
// build links, and the row is about the server, not its plugins.
//
// Mercure and Vulcain are here because they are AGPL-3.0 and they ship. Nothing wikven does
// reaches either -- one is a real-time pub/sub hub, the other a REST push gateway, in a program
// that renders wikitext to files -- but a page that named the permissive two and left the strict
// ones out would be worse than no page. Their /caddy submodules end in "caddy" and are not matched
// a second time.
//
// This asks the build what is in it rather than what was asked for, so a module that leaves the
// build stops being named with nothing here to update.
func runtimeEnv() []string {
	info, ok := debug.ReadBuildInfo()
	if !ok {
		return nil
	}

	var entries []string
	for _, dep := range info.Deps {
		switch {
		case strings.HasSuffix(dep.Path, "/frankenphp"):
			entries = append(entries, "FrankenPHP "+dep.Version)
		case strings.HasSuffix(dep.Path, "/mercure"):
			entries = append(entries, "Mercure "+dep.Version)
		case strings.HasSuffix(dep.Path, "/vulcain"):
			entries = append(entries, "Vulcain "+dep.Version)
		case dep.Path == "github.com/caddyserver/caddy/v2":
			entries = append(entries, "Caddy "+dep.Version)
		}
	}
	if len(entries) == 0 {
		return nil
	}
	return []string{runtimeVar + "=" + strings.Join(entries, runtimeSep)}
}
