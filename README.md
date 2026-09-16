# Simple API Content Sync

This is a small WordPress plugin I created as a code sample.

The idea is simple: pull content from an external REST API and save it into WordPress.

I picked this example because API integrations are something I have worked with a lot, and it shows both WordPress and general PHP development without needing a large project.

## What the plugin does

- adds a small settings page under Tools
- saves an API URL and token
- calls the external API
- caches the response for 10 minutes
- creates WordPress posts from the response
- updates the same post if it was already imported
- uses WordPress permissions, nonces and sanitization

## Why I wrote it this way

I tried to keep the code easy to follow.

The API call, post save logic and post lookup are separate methods because they are different responsibilities and are easier to change that way.

I used the WordPress HTTP API instead of cURL directly because it is the normal WordPress approach and works better with the platform.

I also store the external API ID in post meta. That gives me a simple way to know whether a post already exists and prevents duplicates.

The API response is cached because there is no reason to call the same remote service again immediately.

## Example response

```json
{
  "items": [
    {
      "id": "123",
      "title": "Example article",
      "content": "<p>Example content</p>"
    }
  ]
}
```

## If this was a larger project

For a bigger production integration I would probably add:

- background processing
- logging
- retries for temporary API failures
- pagination
- automated tests
- WP-CLI commands
- better secret management

I left those out here because I wanted the sample to stay small and readable.

## Testing

The main things I would test are:

- a new item creates a post
- the same item updates the existing post
- missing ID or title is skipped
- bad API responses do not create content
- unauthorized users cannot run the sync
- repeated requests use the cached API response

This is a clean sample written for this application and does not contain proprietary client or employer code.
