// Package wikvencaddy registers the "build", "serve" and "translate" subcommands on the wikven
// binary.
package wikvencaddy

import (
	"errors"
	"flag"
	"os"
	"os/exec"
	"path/filepath"

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
			return reexec("file-server",
				"--root", filepath.Join(workdir, "dist"),
				"--listen", fl.String("listen"))
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
func reexec(args ...string) (int, error) {
	self, err := os.Executable()
	if err != nil {
		return 1, err
	}
	cmd := exec.Command(self, args...)
	cmd.Stdin = os.Stdin
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	cmd.Env = os.Environ()
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
