import {
  id = "wikven"
  to = github_repository_pages.this
}
resource "github_repository_pages" "this" {
  repository = github_repository.this.name
  build_type = "legacy"

  source {
    branch = "gh-pages"
    path   = "/"
  }
}
