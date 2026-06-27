// Package wikvencaddy registers "build" and "serve" subcommands on the wikven
// binary, so the static site can be built with `./wikven build` and previewed
// with `./wikven serve` instead of the longer underlying invocations. Both
// re-invoke this same binary: build through the embedded php-cli, serve through
// Caddy's built-in file-server.
package wikvencaddy

import (
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
}

// reexec runs this same binary with the given arguments, wiring through the
// standard streams and the current environment.
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
		return 1, err
	}
	return 0, nil
}
