//go:build tools

// Package tools holds the golangci-lint version lint.yml runs. Dependabot updates only the
// modules a package imports, and a tool directive alone is recorded as indirect.
package tools

import (
	_ "github.com/golangci/golangci-lint/v2/cmd/golangci-lint"
)
