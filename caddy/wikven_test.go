package wikvencaddy

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// The addresses Caddy's --listen takes, against the address a reader is given for each.
func TestPreviewURL(t *testing.T) {
	for listen, want := range map[string]string{
		":8080":            "http://localhost:8080",
		"0.0.0.0:8080":     "http://localhost:8080",
		"[::]:8080":        "http://localhost:8080",
		"localhost:3000":   "http://localhost:3000",
		"127.0.0.1:3000":   "http://127.0.0.1:3000",
		"[::1]:3000":       "http://[::1]:3000",
		"example.test:80":  "http://example.test:80",
		"unix//tmp/wikven": "unix//tmp/wikven",
		"8080":             "8080",
	} {
		if got := previewURL(listen); got != want {
			t.Errorf("previewURL(%q) = %q, want %q", listen, got, want)
		}
	}
}

// A working directory gets the settings written into it, and PHP is pointed at where they went.
func TestPHPEnv(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("PHP_INI_SCAN_DIR", "")

	conf := filepath.Join(dir, ".cache", "php")
	env := phpEnv(dir)
	if want := []string{"PHP_INI_SCAN_DIR=:" + conf}; !slices.Equal(env, want) {
		t.Errorf("phpEnv(%q) = %q, want %q", dir, env, want)
	}

	// The file cache goes where PHP will look for it, and it is there before PHP starts: opcache
	// given a file_cache that is not a directory drops the setting without saying so.
	cache := filepath.Join(dir, ".cache", "opcache")
	if info, err := os.Stat(cache); err != nil || !info.IsDir() {
		t.Errorf("the file cache directory %q is not there: %v", cache, err)
	}
	written, err := os.ReadFile(filepath.Join(conf, "opcache.ini"))
	if err != nil {
		t.Fatalf("reading the settings back: %v", err)
	}
	for _, want := range []string{"opcache.enable_cli=1", "opcache.file_cache=" + cache} {
		if !strings.Contains(string(written), want) {
			t.Errorf("the settings do not carry %q:\n%s", want, written)
		}
	}
}

// A scan directory the caller chose is kept, and read before the one written above.
func TestPHPEnvKeepsTheCallersScanDir(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("PHP_INI_SCAN_DIR", "/etc/theirs")

	want := []string{"PHP_INI_SCAN_DIR=/etc/theirs:" + filepath.Join(dir, ".cache", "php")}
	if env := phpEnv(dir); !slices.Equal(env, want) {
		t.Errorf("phpEnv(%q) = %q, want %q", dir, env, want)
	}
}

// Nowhere to write is a bake that compiles more, not a bake that refuses.
func TestPHPEnvSaysNothingWhenItCannotWrite(t *testing.T) {
	blocked := filepath.Join(t.TempDir(), "file")
	if err := os.WriteFile(blocked, nil, 0o666); err != nil {
		t.Fatalf("making the obstruction: %v", err)
	}
	if env := phpEnv(blocked); env != nil {
		t.Errorf("phpEnv(%q) = %q, want nothing", blocked, env)
	}
}

// The working directory a run uses: what was asked for, or where it was run.
func TestWorkdir(t *testing.T) {
	t.Setenv("WIKVEN_WORKDIR", "/somewhere")
	if got := workdir(); got != "/somewhere" {
		t.Errorf("workdir() = %q, want %q", got, "/somewhere")
	}
	t.Setenv("WIKVEN_WORKDIR", "")
	if got := workdir(); got != "." {
		t.Errorf("workdir() = %q, want %q", got, ".")
	}
}

// The default working directory is a relative one, and what PHP is told has to survive a child
// started from somewhere else.
func TestPHPEnvIsAbsolute(t *testing.T) {
	t.Chdir(t.TempDir())
	t.Setenv("PHP_INI_SCAN_DIR", "")
	// Asked for rather than kept from above: a temporary directory can be reached through a link,
	// and what this compares against is the path a process there actually reports.
	here, err := os.Getwd()
	if err != nil {
		t.Fatalf("asking where we are: %v", err)
	}

	env := phpEnv(".")
	if len(env) != 1 {
		t.Fatalf("phpEnv(\".\") = %q, want one entry", env)
	}
	path := strings.TrimPrefix(strings.TrimPrefix(env[0], "PHP_INI_SCAN_DIR="), ":")
	if !filepath.IsAbs(path) {
		t.Errorf("phpEnv(\".\") points PHP at %q, which is not an absolute path", path)
	}
	settings, err := os.ReadFile(filepath.Join(path, "opcache.ini"))
	if err != nil {
		t.Fatalf("reading the settings back: %v", err)
	}
	if !strings.Contains(string(settings), "opcache.file_cache="+filepath.Join(here, ".cache", "opcache")) {
		t.Errorf("the file cache is not where the run can find it:\n%s", settings)
	}
}

// What reaches a translation helper: the caller's own words after the subcommand, and nothing the
// scan could have picked up on its way there.
func TestCommandTail(t *testing.T) {
	for _, c := range []struct {
		why  string
		args []string
		want []string
	}{
		{
			why:  "the words after the subcommand, in the order they were written",
			args: []string{"/usr/local/bin/wikven", "translate", "mark", "--all"},
			want: []string{"mark", "--all"},
		},
		{
			// Why the scan starts at argv[1]. From argv[0] this would match the executable itself
			// and hand back "translate mark", giving the helper the command word as a file name.
			why:  "a binary installed under the subcommand's own name does not match itself",
			args: []string{"translate", "translate", "mark"},
			want: []string{"mark"},
		},
		{
			why:  "a subcommand with nothing after it has an empty tail, not a missing one",
			args: []string{"wikven", "translate"},
			want: []string{},
		},
		{
			why:  "the first occurrence is the subcommand; a later one is the caller's word",
			args: []string{"wikven", "translate", "mark", "translate"},
			want: []string{"mark", "translate"},
		},
		{
			why:  "a command line the subcommand is not on",
			args: []string{"wikven", "build"},
			want: nil,
		},
	} {
		t.Run(c.why, func(t *testing.T) {
			original := os.Args
			t.Cleanup(func() { os.Args = original })
			os.Args = c.args

			if got := commandTail("translate"); !slices.Equal(got, c.want) {
				t.Errorf("commandTail(\"translate\") over %q = %#v, want %#v", c.args, got, c.want)
			}
		})
	}
}
