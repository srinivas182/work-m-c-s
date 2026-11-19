output "bucket" {
  value = aws_s3_bucket.files.bucket
}

output "bucket_arn" {
  value = aws_s3_bucket.files.arn
}

output "logs_bucket" {
  value = aws_s3_bucket.logs.bucket
}

output "public_url" {
  description = "FILES_PUBLIC_URL for the app"
  value       = "https://${coalesce(var.public_domain, aws_cloudfront_distribution.public.domain_name)}"
}

output "cloudfront_domain" {
  description = "Point the public files address (CNAME, not proxied by Cloudflare) here"
  value       = aws_cloudfront_distribution.public.domain_name
}
