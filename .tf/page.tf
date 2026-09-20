import {
  id = "wikven"
  to = github_repository_pages.this
}
# Served by a workflow rather than a branch. Nothing else can be: gh-pages holds what a push to
# main baked and previews holds what each open pull request baked, and the site is the two laid
# over each other -- which is a thing only publish-pages.yml assembles, and never a branch.
resource "github_repository_pages" "this" {
  repository = github_repository.this.name
  build_type = "workflow"
}
