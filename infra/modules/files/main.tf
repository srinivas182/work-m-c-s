# Files (Sprint 17.1): one private bucket per environment, every business's files under tenants/<id>/ as today.
# - Versioned, so a deleted or overwritten file can be brought back for 90 days (the restore runbook, 17.4).
# - Encrypted with the environment's key; TLS only; never public. Public website photos and videos (the `public`
#   prefix) are served through CloudFront with origin access control — CloudFront can read only that prefix.
# - Object ACLs are ignored (the app asks for "public" on website photos; the bucket never honours it).
# - A second bucket keeps access logs.

terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 5.80" }
  }
}

data "aws_caller_identity" "this" {}

resource "aws_s3_bucket" "files" {
  #checkov:skip=CKV2_AWS_6:it has one — public ACLs are ignored and public policies blocked (see below)
  bucket = "${var.name}-files-${data.aws_caller_identity.this.account_id}"
}

resource "aws_s3_bucket_ownership_controls" "files" {
  bucket = aws_s3_bucket.files.id
  rule {
    object_ownership = "BucketOwnerPreferred" # accepts the app's ACL header; public ACLs are ignored below
  }
}

resource "aws_s3_bucket_public_access_block" "files" {
  #checkov:skip=CKV_AWS_53:public ACLs are accepted on upload (Flysystem sends one) but ignored, and public policies blocked
  bucket                  = aws_s3_bucket.files.id
  block_public_acls       = false
  ignore_public_acls      = true
  block_public_policy     = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "files" {
  bucket = aws_s3_bucket.files.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "files" {
  bucket = aws_s3_bucket.files.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = "aws:kms"
      kms_master_key_id = var.kms_key_arn
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "files" {
  bucket     = aws_s3_bucket.files.id
  depends_on = [aws_s3_bucket_versioning.files]

  rule {
    id     = "keep-old-versions-90-days"
    status = "Enabled"
    filter {}
    noncurrent_version_expiration {
      noncurrent_days = var.noncurrent_version_days
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }
}

resource "aws_s3_bucket_logging" "files" {
  bucket        = aws_s3_bucket.files.id
  target_bucket = aws_s3_bucket.logs.id
  target_prefix = "s3/files/"
}

resource "aws_s3_bucket_policy" "files" {
  bucket     = aws_s3_bucket.files.id
  depends_on = [aws_s3_bucket_public_access_block.files]
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "TlsOnly"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource  = [aws_s3_bucket.files.arn, "${aws_s3_bucket.files.arn}/*"]
        Condition = { Bool = { "aws:SecureTransport" = "false" } }
      },
      {
        Sid       = "CloudFrontReadsPublicPrefixOnly"
        Effect    = "Allow"
        Principal = { Service = "cloudfront.amazonaws.com" }
        Action    = "s3:GetObject"
        Resource  = "${aws_s3_bucket.files.arn}/public/*"
        Condition = { StringEquals = { "AWS:SourceArn" = aws_cloudfront_distribution.public.arn } }
      },
    ]
  })
}

# --- Access logs ------------------------------------------------------------------------------------------------------

resource "aws_s3_bucket" "logs" {
  #checkov:skip=CKV_AWS_145:ELB access logs only support SSE-S3
  #checkov:skip=CKV_AWS_18:this is the log bucket
  #checkov:skip=CKV2_AWS_62:no events needed on logs
  #checkov:skip=CKV_AWS_144:logs stay in the region
  bucket = "${var.name}-logs-${data.aws_caller_identity.this.account_id}"
}

resource "aws_s3_bucket_ownership_controls" "logs" {
  bucket = aws_s3_bucket.logs.id
  rule {
    object_ownership = "BucketOwnerPreferred" # CloudFront standard logs need ACLs
  }
}

resource "aws_s3_bucket_public_access_block" "logs" {
  bucket                  = aws_s3_bucket.logs.id
  block_public_acls       = true
  ignore_public_acls      = true
  block_public_policy     = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "logs" {
  bucket = aws_s3_bucket.logs.id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "logs" {
  bucket = aws_s3_bucket.logs.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "logs" {
  bucket = aws_s3_bucket.logs.id
  rule {
    id     = "expire"
    status = "Enabled"
    filter {}
    expiration {
      days = var.log_retention_days
    }
    noncurrent_version_expiration {
      noncurrent_days = 7
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }
}

# The load balancers in af-south-1 write access logs as this AWS account (an older region: no service principal).
resource "aws_s3_bucket_policy" "logs" {
  bucket = aws_s3_bucket.logs.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "TlsOnly"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource  = [aws_s3_bucket.logs.arn, "${aws_s3_bucket.logs.arn}/*"]
        Condition = { Bool = { "aws:SecureTransport" = "false" } }
      },
      {
        Sid       = "LoadBalancerAccessLogs"
        Effect    = "Allow"
        Principal = { AWS = "arn:aws:iam::${var.elb_account_id}:root" }
        Action    = "s3:PutObject"
        Resource  = "${aws_s3_bucket.logs.arn}/alb/AWSLogs/${data.aws_caller_identity.this.account_id}/*"
      },
    ]
  })
}

# --- Public website files through CloudFront ---------------------------------------------------------------------------

resource "aws_cloudfront_origin_access_control" "files" {
  name                              = "${var.name}-files"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

resource "aws_cloudfront_distribution" "public" {
  #checkov:skip=CKV_AWS_68:WAF sits at Cloudflare for the app; these are static, signed-origin files
  #checkov:skip=CKV_AWS_310:one origin; S3 is regional and versioned
  #checkov:skip=CKV_AWS_374:no geo restriction: customers anywhere see the sites
  #checkov:skip=CKV2_AWS_47:no WAF on static files (see above)
  enabled         = true
  comment         = "WURK ${var.environment}: public website photos and videos"
  is_ipv6_enabled = true
  http_version    = "http2and3"
  price_class     = "PriceClass_All" # includes Johannesburg and Cape Town edges
  aliases         = var.public_domain == null ? [] : [var.public_domain]

  origin {
    origin_id                = "files"
    domain_name              = aws_s3_bucket.files.bucket_regional_domain_name
    origin_access_control_id = aws_cloudfront_origin_access_control.files.id
  }

  default_cache_behavior {
    target_origin_id           = "files"
    viewer_protocol_policy     = "redirect-to-https"
    allowed_methods            = ["GET", "HEAD"]
    cached_methods             = ["GET", "HEAD"]
    compress                   = true
    cache_policy_id            = "658327ea-f89d-4fab-a63d-7e88639e58f6" # Managed-CachingOptimized
    response_headers_policy_id = "67f7725c-6f97-4210-82d7-5512b31e9d03" # Managed-SecurityHeadersPolicy
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    cloudfront_default_certificate = var.public_domain == null
    acm_certificate_arn            = var.public_certificate_arn
    ssl_support_method             = var.public_domain == null ? null : "sni-only"
    minimum_protocol_version       = var.public_domain == null ? "TLSv1" : "TLSv1.2_2021"
  }

  logging_config {
    bucket = aws_s3_bucket.logs.bucket_domain_name
    prefix = "cloudfront/"
  }

  depends_on = [aws_s3_bucket_ownership_controls.logs]
}
