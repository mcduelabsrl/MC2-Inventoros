<?php

/**
 * Runs once when the plugin is deactivated. Nothing to stop: while inactive
 * the plugin registers no hooks, so no stock is pushed, and its webhook route
 * is gone, so Shopify's deliveries fail and Shopify retries them for a while.
 * Connections and the import log are kept for when it comes back.
 */
