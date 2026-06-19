"""MinIO / S3-compatible client for result uploads (boto3, path-style addressing)."""

import os
from pathlib import Path
from typing import Any

import boto3
from botocore.client import Config

_S3_ENDPOINT = os.getenv("S3_ENDPOINT", "")
_S3_KEY = os.getenv("S3_KEY", "")
_S3_SECRET = os.getenv("S3_SECRET", "")
_S3_REGION = os.getenv("S3_REGION", "us-east-1")
_S3_USE_PATH_STYLE = os.getenv("S3_USE_PATH_STYLE", "true").strip().lower() in (
    "1", "true", "yes", "on"
)

_client: Any = None


def _make_client() -> Any:
    addressing_style = "path" if _S3_USE_PATH_STYLE else "virtual"
    cfg = Config(s3={"addressing_style": addressing_style})
    return boto3.client(
        "s3",
        endpoint_url=_S3_ENDPOINT or None,
        aws_access_key_id=_S3_KEY or None,
        aws_secret_access_key=_S3_SECRET or None,
        region_name=_S3_REGION or "us-east-1",
        config=cfg,
    )


def put_file(local_path: str, bucket: str, key: str, content_type: str) -> dict[str, Any]:
    """Upload local_path to bucket/key; return {bucket, key, size, mime}."""
    global _client
    if _client is None:
        _client = _make_client()

    path = Path(local_path)
    size = path.stat().st_size
    with path.open("rb") as fh:
        _client.put_object(
            Bucket=bucket,
            Key=key,
            Body=fh,
            ContentType=content_type,
            ContentLength=size,
        )
    return {"bucket": bucket, "key": key, "size": size, "mime": content_type}
