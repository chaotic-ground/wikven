import {
  id = "wikven:17638220"
  to = github_repository_ruleset.default
}
resource "github_repository_ruleset" "default" {
  name        = "default"
  repository  = github_repository.this.name
  target      = "branch"
  enforcement = "active"

  # Declare bypass actors only on local PAT runs; they're admin-only and unreadable by CI's token.
  dynamic "bypass_actors" {
    for_each = var.github_actions ? [] : [
      { actor_id = 5, actor_type = "RepositoryRole" }, # Repository admin
      { actor_id = 17810468, actor_type = "Team" },    # chaotic-ground/publishers
    ]
    content {
      actor_id    = bypass_actors.value.actor_id
      actor_type  = bypass_actors.value.actor_type
      bypass_mode = "always"
    }
  }

  conditions {
    ref_name {
      include = ["~DEFAULT_BRANCH"]
      exclude = []
    }
  }

  rules {
    # Protect default branch: block deletion and force-pushes, and require a PR for every change.
    deletion         = true
    non_fast_forward = true
    update           = false

    # Merge commits are the only allowed merge method, so linear history must not be required.
    required_linear_history = false
    required_signatures     = false

    pull_request {
      dismiss_stale_reviews_on_push     = false
      require_code_owner_review         = false
      require_last_push_approval        = false
      required_approving_review_count   = 0
      required_review_thread_resolution = false
    }

    # Required: Lint jobs + semantic-pull-request (integration_id 15368 = GitHub Actions app).
    required_status_checks {
      do_not_enforce_on_create             = false
      strict_required_status_checks_policy = false

      dynamic "required_check" {
        for_each = [
          "biome",
          "mago",
          "rumdl",
          "semantic-pull-request",
          "taplo",
          "typos",
          "yamllint",
          "zizmor",
        ]
        content {
          context        = required_check.value
          integration_id = 15368
        }
      }
    }
  }
}
