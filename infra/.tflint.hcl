# TFLint (Sprint 17.1): OpenTofu style and AWS mistakes (instance types that don't exist, invalid values) before a plan.
config {
  call_module_type = "local"
}

plugin "terraform" {
  enabled = true
  preset  = "recommended"
}

plugin "aws" {
  enabled = true
  version = "0.36.0"
  source  = "github.com/terraform-linters/tflint-ruleset-aws"
}
