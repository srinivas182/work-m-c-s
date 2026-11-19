# The release pipeline's way into an environment (Sprint 17.2): GitHub Actions signs in with OpenID Connect — no AWS
# keys stored anywhere — and may assume this role only from WURK's repository, in the matching GitHub environment
# ("production" requires an approval, set in GitHub). It can push images and roll out releases; it can't read data,
# change the network or delete anything but old task definitions.

terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 5.80" }
  }
}

data "aws_region" "this" {}
data "aws_caller_identity" "this" {}

resource "aws_iam_openid_connect_provider" "github" {
  count          = var.create_oidc_provider ? 1 : 0
  url            = "https://token.actions.githubusercontent.com"
  client_id_list = ["sts.amazonaws.com"]
  # AWS no longer checks these for GitHub (it trusts GitHub's certificate authority), but the provider requires them.
  thumbprint_list = ["6938fd4d98bab03faadb97b34396831e3780aea1", "1c58a3a8518e8759bf075b76b750d4f2df264fcd"]
}

data "aws_iam_openid_connect_provider" "github" {
  count = var.create_oidc_provider ? 0 : 1
  url   = "https://token.actions.githubusercontent.com"
}

locals {
  oidc_arn = var.create_oidc_provider ? aws_iam_openid_connect_provider.github[0].arn : data.aws_iam_openid_connect_provider.github[0].arn
  account  = data.aws_caller_identity.this.account_id
  region   = data.aws_region.this.name
}

resource "aws_iam_role" "deploy" {
  name                 = "${var.name}-deploy"
  max_session_duration = 7200 # migrations, rollout and the 10-minute watch
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Federated = local.oidc_arn }
      Action    = "sts:AssumeRoleWithWebIdentity"
      Condition = {
        StringEquals = {
          "token.actions.githubusercontent.com:aud" = "sts.amazonaws.com"
          "token.actions.githubusercontent.com:sub" = "repo:${var.github_repository}:environment:${var.environment}"
        }
      }
    }]
  })
}

resource "aws_iam_role_policy" "deploy" {
  #checkov:skip=CKV_AWS_355:"*" only for actions without resource-level permissions (registry login, task definitions, describes)
  role = aws_iam_role.deploy.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Sid = "RegistryLogin", Effect = "Allow", Action = ["ecr:GetAuthorizationToken"], Resource = "*" },
      {
        Sid    = "PushImages"
        Effect = "Allow"
        Action = ["ecr:BatchCheckLayerAvailability", "ecr:InitiateLayerUpload", "ecr:UploadLayerPart", "ecr:CompleteLayerUpload", "ecr:PutImage",
        "ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer", "ecr:DescribeImages", "ecr:DescribeRepositories"]
        Resource = var.repository_arn
      },
      { Sid = "UseTheKey", Effect = "Allow", Action = ["kms:Decrypt", "kms:GenerateDataKey"], Resource = var.kms_key_arn },
      {
        Sid      = "TaskDefinitions"
        Effect   = "Allow"
        Action   = ["ecs:DescribeTaskDefinition", "ecs:RegisterTaskDefinition", "ecs:ListTaskDefinitions", "ecs:DeregisterTaskDefinition"]
        Resource = "*" # these actions don't support resource-level permissions
      },
      {
        Sid      = "WatchReleaseTask"
        Effect   = "Allow"
        Action   = ["ecs:DescribeTasks"]
        Resource = "arn:aws:ecs:${local.region}:${local.account}:task/${var.cluster_name}/*"
      },
      {
        Sid      = "RollOut"
        Effect   = "Allow"
        Action   = ["ecs:DescribeServices", "ecs:UpdateService"]
        Resource = "arn:aws:ecs:${local.region}:${local.account}:service/${var.cluster_name}/*"
      },
      {
        Sid       = "ReleaseTask"
        Effect    = "Allow"
        Action    = ["ecs:RunTask"]
        Resource  = "arn:aws:ecs:${local.region}:${local.account}:task-definition/${var.name}-release:*"
        Condition = { ArnEquals = { "ecs:cluster" = "arn:aws:ecs:${local.region}:${local.account}:cluster/${var.cluster_name}" } }
      },
      { Sid = "PassTaskRoles", Effect = "Allow", Action = ["iam:PassRole"], Resource = var.task_role_arns, Condition = { StringEquals = { "iam:PassedToService" = "ecs-tasks.amazonaws.com" } } },
      {
        Sid      = "Snapshots"
        Effect   = "Allow"
        Action   = ["rds:CreateDBSnapshot", "rds:AddTagsToResource"]
        Resource = concat(var.database_arns, ["arn:aws:rds:${local.region}:${local.account}:snapshot:${var.name}-*"])
      },
      { Sid = "Look", Effect = "Allow", Action = ["rds:DescribeDBInstances", "rds:DescribeDBSnapshots", "elasticloadbalancing:DescribeLoadBalancers", "cloudwatch:GetMetricData"], Resource = "*" },
      {
        Sid      = "PullFromStaging"
        Effect   = "Allow"
        Action   = ["ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer", "ecr:BatchCheckLayerAvailability"]
        Resource = coalesce(var.source_repository_arn, var.repository_arn)
      },
      { Sid = "ReleaseLogs", Effect = "Allow", Action = ["logs:GetLogEvents", "logs:FilterLogEvents"], Resource = "arn:aws:logs:${local.region}:${local.account}:log-group:/wurk/${var.name}/release:*" },
    ]
  })
}
