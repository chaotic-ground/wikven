import {
  id = "wikven"
  to = github_repository.this
}
resource "github_repository" "this" {
  allow_auto_merge            = true
  allow_merge_commit          = true
  allow_rebase_merge          = false
  allow_squash_merge          = false
  allow_update_branch         = true
  archived                    = false
  archive_on_destroy          = true
  auto_init                   = false
  delete_branch_on_merge      = true
  description                 = "A static web site generator using MediaWiki."
  has_discussions             = false
  has_issues                  = true
  has_projects                = false
  has_wiki                    = false
  homepage_url                = "https://chaotic-ground.github.io/wikven/"
  merge_commit_message        = "PR_BODY"
  merge_commit_title          = "PR_TITLE"
  name                        = "wikven"
  topics                      = ["docker-image", "mediawiki", "static-site-generator", "wikitext"]
  visibility                  = "public"
  vulnerability_alerts        = var.github_actions ? null : true
  web_commit_signoff_required = false

  dynamic "security_and_analysis" {
    for_each = var.github_actions ? [] : [true]
    content {
      secret_scanning {
        status = "enabled"
      }
      secret_scanning_push_protection {
        status = "enabled"
      }
    }
  }

  lifecycle {
    ignore_changes = [
      # Cannot be imported
      archive_on_destroy,
      # Deprecated
      ignore_vulnerability_alerts_during_read,
    ]
  }
}
