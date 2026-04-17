"""Scrapy settings for the crawler project."""

from __future__ import annotations

BOT_NAME = "lead_crawler"

SPIDER_MODULES = ["lead_crawler.spiders"]
NEWSPIDER_MODULE = "lead_crawler.spiders"

ROBOTSTXT_OBEY = True

ITEM_PIPELINES = {
    "lead_crawler.pipelines.LeadPagePipeline": 300,
}

# Placeholder for Laravel API endpoint integration.
LARAVEL_API_BASE_URL = "http://backend.local/api"
