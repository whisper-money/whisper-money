# Changelog

Every change to the API that could affect your code, newest first.

{{TOC}}

## v1: initial release

The first public version of the API, under `https://whisper.money/api/v1`.

## How versions will work

`/api/v1` stays as it is. We do not remove fields or endpoints from it, nor
change what they mean.

When a breaking change becomes necessary, it will ship as a dated version that
you opt into with a `Whisper-Version` header, while requests without the header
keep today's behaviour. That header is not live yet: sending it now has no
effect. Changes that cannot break a client, such as a new endpoint or a new
field in a response, are added to v1 directly and listed here.
