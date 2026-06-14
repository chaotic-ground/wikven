// Package wikvencaddy registers a "build" subcommand on the wikven binary, so
// the static site can be built with `./wikven build` instead of the longer
// `./wikven php-cli build.php`. It simply re-invokes the binary's embedded
// build.php through php-cli.
package wikvencaddy

import (
	"os"
	"os/exec"

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
			self, err := os.Executable()
			if err != nil {
				return 1, err
			}
			cmd := exec.Command(self, "php-cli", "build.php")
			cmd.Stdin = os.Stdin
			cmd.Stdout = os.Stdout
			cmd.Stderr = os.Stderr
			cmd.Env = os.Environ()
			if err := cmd.Run(); err != nil {
				return 1, err
			}
			return 0, nil
		},
	})
}
