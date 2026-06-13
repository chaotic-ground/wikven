resource "github_repository_ruleset" "default" {
  name        = "default"
  repository  = github_repository.this.name
  target      = "branch"
  enforcement = "active"

  conditions {
    ref_name {
      include = ["~DEFAULT_BRANCH"]
      exclude = []
    }
  }

  rules {
    # Protect the default branch: block deletion and force-pushes, and require a
    # pull request for every change (so direct pushes to main are not allowed).
    deletion         = true
    non_fast_forward = true
    update           = false

    # Merge commits are the only allowed merge method, so a linear history must
    # not be required (requiring it would forbid merge commits).
    required_linear_history = false
    required_signatures     = false

    pull_request {
      dismiss_stale_reviews_on_push     = false
      require_code_owner_review         = false
      require_last_push_approval        = false
      required_approving_review_count   = 0
      required_review_thread_resolution = false
    }

    # Require the checks from the Lint workflow (.github/workflows/lint.yaml) to
    # pass before a pull request can be merged. integration_id 15368 is the
    # GitHub Actions app, so only its check runs satisfy these.
    required_status_checks {
      do_not_enforce_on_create             = false
      strict_required_status_checks_policy = false

      dynamic "required_check" {
        for_each = [
          "biome",
          "mago",
          "rumdl",
          "taplo",
          "typos",
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
