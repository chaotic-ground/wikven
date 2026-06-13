import {
  id = "wikven:17638220"
  to = github_repository_ruleset.default
}
resource "github_repository_ruleset" "default" {
  name        = "default"
  repository  = github_repository.this.name
  target      = "branch"
  enforcement = "active"

  # Let repository admins and the chaotic-ground/publishers team bypass the
  # rules. Declared unconditionally (not gated on github_actions): the stateless
  # CI run re-imports the ruleset every time, so the config must match reality
  # or it reports drift forever. Rulesets are readable with plain repo read, so
  # the Actions GITHUB_TOKEN can still refresh them.
  bypass_actors {
    actor_id    = 5 # Repository admin
    actor_type  = "RepositoryRole"
    bypass_mode = "always"
  }
  bypass_actors {
    actor_id    = 17810468 # chaotic-ground/publishers
    actor_type  = "Team"
    bypass_mode = "always"
  }

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

    # Require status checks to pass before a pull request can be merged: the
    # Lint workflow jobs (.github/workflows/lint.yaml) and the semantic pull
    # request title check. integration_id 15368 is the GitHub Actions app, so
    # only its check runs satisfy these.
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
