package wikvencaddy

import (
	"strings"
	"testing"
)

// The candidate order is the whole of what this file exists for: ".html first" is what makes
// /Configuration answer with Configuration.html rather than with the Configuration/ directory
// beside it, which is what the host does.
func TestPreviewConfigTriesTheHtmlNameFirst(t *testing.T) {
	config := previewConfig("/w/dist", ":8080")
	if !strings.Contains(config, "try_files {path}.html {path}\n") {
		t.Fatalf("no try_files in:\n%s", config)
	}
}

// The port alone, so a preview answers whatever Host a reader's browser sends: localhost, an IP,
// a container's name. A site address naming a host would answer 400 to the other two.
func TestPreviewConfigServesEveryHostByDefault(t *testing.T) {
	config := previewConfig("/w/dist", ":8080")
	if !strings.Contains(config, "\n:8080 {\n") {
		t.Errorf("expected a port-only site address in:\n%s", config)
	}
	if strings.Contains(config, "bind") {
		t.Errorf("expected no bind for an address that names no interface:\n%s", config)
	}
}

func TestPreviewConfigKeepsAnInterfaceTheCallerNamed(t *testing.T) {
	config := previewConfig("/w/dist", "127.0.0.1:9000")
	if !strings.Contains(config, "\n:9000 {\n\tbind \"127.0.0.1\"\n") {
		t.Errorf("expected 127.0.0.1 bound on port 9000 in:\n%s", config)
	}
}

// A flag value the flag's documentation does not promise, but a caller can plausibly write.
func TestPreviewConfigTakesABarePort(t *testing.T) {
	config := previewConfig("/w/dist", "9000")
	if !strings.Contains(config, "\n:9000 {\n") {
		t.Errorf("expected port 9000 in:\n%s", config)
	}
}

// A working directory is wherever the caller put it, and a Caddyfile token ends at a space.
func TestPreviewConfigQuotesTheRoot(t *testing.T) {
	config := previewConfig(`/home/a b/say "hi"/dist`, ":8080")
	if !strings.Contains(config, `root * "/home/a b/say \"hi\"/dist"`) {
		t.Errorf("root not quoted and escaped in:\n%s", config)
	}
}
