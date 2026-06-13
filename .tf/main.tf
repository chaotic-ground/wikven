terraform {
  required_version = "~> 1.0"

  backend "local" {}

  required_providers {
    github = {
      source  = "integrations/github"
      version = "~> 6.0"
    }
  }
}

provider "github" {
  owner = "chaotic-ground"
}
