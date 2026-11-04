# Brand Pack

A WordPress plugin that turns your site into a brand pack AI agents read before they write, design or review anything for you. Voice, visual identity, rules and facts in one file, kept in sync with your site.

**[Try it in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/jamiemarsland/brand-pack/main/blueprint.json)** (loads a fictional bakery as demo content)

## What agents get

- `/brand.md` and `/.well-known/brand.json`
- REST: `/wp-json/brand-pack/v1/pack`
- WordPress abilities: `brand-pack/get-pack` and `brand-pack/check-text`
- A downloadable skill zip (`SKILL.md`, `brand.json`, logo)

The pack is private by default. Agents need the access key from **Brand Pack → Connect agents**, sent as an `Authorization: Bearer` header or as `?key=`.

Requires WordPress 6.9+ and PHP 7.4+.
