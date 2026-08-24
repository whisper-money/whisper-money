# Imports

Imports let you bring bank files into Whisper Money when automatic syncing is not available or when you want more control.

{{TOC}}

## Quick start

1. Choose the account.
2. Upload the bank file.
3. Map the columns.
4. Check the preview.
5. Import selected transactions.
6. Review categories and duplicates after import.

## Import flow

```mermaid
flowchart TD
    account[Choose account] --> file[Upload file]
    file --> mapping[Map columns]
    mapping --> preview[Preview]
    preview --> import[Import]
    import --> review[Review transactions]
```

## Required columns

<div class="cards-wrapper">

<div class="card">
### Date

The transaction date.

Whisper Money can detect common date formats, but you can adjust it if needed.

</div>

<div class="card">
### Description

The text that explains the transaction.

You can combine description columns when the bank splits details across fields.

</div>

<div class="card">
### Amount

The transaction amount.

Make sure income and expenses use the correct sign.

</div>

<div class="card">
### Balance

Optional.

Use this when the file includes running account balances.

</div>
</div>

## Balances and imports

Some files include a running balance column and some do not.

When the file has one, map it and the balance history follows the import. When it
does not, the import brings in the transactions only, and the account keeps
whatever balances you have entered yourself.

Working out the balance history from the transactions and one known balance is
not available yet. Until it is, add a balance manually on the account when you
want the net worth chart to be right.

## Preview before importing

Always review the preview.

Look for:

- Rows flagged as duplicates that are actually two real payments of the same
  amount on the same day.
- Wrong dates.
- Amounts with the wrong sign.
- Duplicate transactions.
- Missing descriptions.
- Unexpected empty rows.

## Automation during import

Automation rules can help categorize imported transactions.

This works best when descriptions are consistent. If imported rows come from the same bank file format every time, rules become very useful.

## FAQ

### Which file should I use?

Use the cleanest export your bank provides. CSV and spreadsheet-style files are usually easiest.

### Why are amounts reversed?

Some banks export expenses as positive numbers. Check the preview before importing.

### Can I import the same file twice?

Yes. Before the preview, Whisper Money checks each row against the transactions
the account already has and flags the ones that match on the same day, amount,
and description. Flagged rows are unticked for you, so importing the file again
brings in only what is new. The count is shown above the preview, and you can
still tick a row back on if it is a genuine repeat payment.
