<!-- rumdl-disable MD034 MD013 -->
# oqlf_ratelimit

> a Typo3 middleware to limit legitimate and illegitimate bots from hammering on computationally expensive website resources.

## What does it do?

This extension allows you to set global and per IP group limits on how many times a resource can be requested in a configurable timeframe. When this limit is reached, the clients receive a `429 Too Many Requests` and a `Retry-After:` header telling them to cool down and retry at a slower pace.

[Rate limiting with 429 Too Many Requests](https://developer.mozilla.org/en-US/docs/Glossary/Rate_limit)

## Why was this extension created?

My Typo3 website has a multi-page generated sitemap that requires a lot of database calls to produce. Search engine crawlers will request the index and then proceed to download all the pages in rapid succession, which bring my infrastructure to its knees. With oqlf_ratelimit, I set /sitemap.xml (which covers /sitemap.xml, /sitemap.xml?page=1, /sitemap.xml?page=2,..) to have a maximum of 5 calls total / 10 seconds or 3 calls in an IP group / 10 seconds. With this limit, a well-behaved search engine crawler will download the first 3 pages, then get a 429 on the next few requests. It will understand that it needs to slow down and retry at a slower pace.

It's particularly effective on Googlebot, which reacts very nicely and quickly adapts to your rules. In the case of not-so-polite search engine bots, AI learning crawlers, or even illegitimate bots, they will be limited to a few costly requests and then will only receive free-to-you empty 429 replies.

Inspired by <https://www.digitalocean.com/community/tutorials/how-to-implement-php-rate-limiting-with-redis-on-ubuntu-20-04>

## Configuration

All configuration is done in the Settings->Extension configuration module.

### Extension setup

![Redis server setup](Resources/Public/Redis.png)

#### Redis server address

#### Redis server port

#### Treat all requests without a HTTP_REFERER header as a single user

When this is checked, all requests without an HTTP_REFERER are treated as originating from a single user so IP group limit apply

#### Treat all requests with the same value for these headers as a single user

If a request contain one of there headers, the unique values are treated as identifying a single user, so IP group limit apply. I use this by having my frontend reverse proxy flag certain request by adding a custom header, which oqlf_ratelimit use to group users.

Ex:

- if you put "User-agent" here, all users with the same user agent will be counted together.
- if you put "X-Suspicious" here and your firewall add "X-Suspicious: 1" on certain requests, all those flagged requests are grouped as one user.

These headers are tested in order, the request counting stop at the first limit reached.

#### Does reaching a per user limit reset the timer?

When this is checked, when blocked, the period reset on every subsequent request. This is to force the offending user to back-off before being allowed to resume.

#### Comma separated list of IPs to exclude from all limits

Whitelisted IPs which are never checked. Ex: 127.0.0.1

#### Message to display to users receiving a 429

HTML content to display to users hitting a 429 limit. A decreasing bar is displayed after this HTML to show de remaining time before the limit ends and the page is reloaded. With `<h3>Please wait</h3>` in this field: ![Please wait](Resources/Public/Progress.png)

### Page limit setup

Up to 5 different limited resources can be set. Leave the path field empty if unused.

The path field will be matched against the start of the URL after the domain and the leading slash. The example below match https://www.example.com/**sitemap.xml** which will limit requests for:

-  https://www.example.com/**sitemap.xml**
-  https://www.example.com/**sitemap.xml**/anything
-  https://www.example.com/**sitemap.xml**?page=12

![Page limit setup](Resources/Public/Page_limit.png)

## Requirements

As of now, I haven't tested on TYPO3 lower than v13, but I see no reason it wouldn't work on v12, maybe even v11 and v10. I'll update the requirements if I have returns from other users or have time to test myself sometime.

It also requires a Redis or Valkey server v7 or greater for both, which you may already be using for the Typo3 cache. In this case, you can just use the same server.
