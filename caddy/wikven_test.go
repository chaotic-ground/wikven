package wikvencaddy

import "testing"

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
