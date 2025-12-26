# Wiretap

MediaWiki extension for user pageview tracking.

## Requirements

- MediaWiki 1.43+

## Installation

1. Obtain the code from [GitHub](https://github.com/enterprisemediawiki/Wiretap)
2. Extract the files in a directory called ``Wiretap`` in your ``extensions/`` folder.
3. Add the following code at the bottom of your ``LocalSettings.php`` file:

	```php
	wfLoadExtension( 'Wiretap' );
	```

4. In the command line run `php maintenance/update.php`
5. Go to ``Special:Version`` on your wiki to verify that the extension is successfully installed.

## Upgrading

If upgrading, make sure to run `php maintenance/update.php`.

To (re)build the denormalized hit totals tables, also run:

`php extensions/Wiretap/wiretapRecordPageHitCount.php --type=all`

## Important note

This extension is intended for internal corporate wikis where transparency is more
important than privacy. It is definitely very invasive for an open, internet-facing
wiki. If you insist on installing it on a public wiki please make your users aware
that they are not browsing with the anonymity they are familiar with from MediaWiki.

## Config

These settings can be set in ``LocalSettings.php``:

```php
// for selecting a short period over which to count hits to pages
// set to 1 to count over the last day, 4 over the last 4 days, etc
$wgWiretapCounterPeriod = 30;

// use the all-time counter by default
$wgWiretapAddToAlltimeCounter = true;

// don't use the period counter by default
$wgWiretapAddToPeriodCounter = false;

// of course we want counters! why else have the extension!
$wgDisableCounters = false;
```
