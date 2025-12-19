# SOP: Handling Twitch Duplicate Message Anti-Spam Characters

## Problem

Twitch IRC adds invisible characters to duplicate messages as an anti-spam measure. When a user sends the same message twice in a row (e.g., `!cfind josh` followed by `!cfind josh`), Twitch modifies the second message to make it "unique."

## Symptoms

- Bot command works the first time but fails on identical back-to-back usage
- Logs show extra arguments with invisible characters
- Queries have trailing spaces or invisible characters appended

## Root Cause

Twitch adds invisible Unicode characters to duplicate messages:

1. **Empty strings** - `["josh", ""]` - adds empty string as extra argument
2. **U+034F** (Combining Grapheme Joiner) - `["josh", "\u034f"]`
3. **U+200B-U+200D** (Zero-width characters)
4. **U+FEFF** (Byte Order Mark)

When these are joined with spaces (`args.join(' ')`), they create queries like `"josh "` (trailing space) which fail searches.

## Diagnosis

Add debug logging to see raw args:

```javascript
console.log(`!command raw args: ${JSON.stringify(args)} (${args.length} items)`);
if (args.length > 0) {
  console.log(`first arg bytes: ${Buffer.from(args[0]).toString('hex')}`);
}
```

Check logs for:
- Extra empty strings in args array
- Unicode escape sequences like `\u034f`
- Unexpected arg counts

## Solution

Filter out empty and invisible-character-only arguments before processing:

```javascript
// Filter out empty args and invisible Unicode chars (Twitch adds these to duplicate messages)
// \u034f = Combining Grapheme Joiner, \u200B-\u200D = zero-width chars, \uFEFF = BOM
const cleanArgs = args.filter(a => a && a.replace(/[\u034f\u200B-\u200D\uFEFF\s]/g, ''));
const query = cleanArgs.join(' ').trim();
```

This:
1. Filters out falsy args (empty strings, null, undefined)
2. Filters out args that become empty after removing invisible chars and whitespace
3. Joins remaining clean args and trims result

## Why Other Commands Don't Need This

Commands like `!pclip 1` or `!clip` work fine because:
- `!pclip` uses `parseInt(args[0])` which ignores extra args
- `!clip` takes no arguments

Only commands that join all args into free-form text (like `!cfind <query>`) are affected.

## Files Modified

- `bot-service/bot.js` - Added invisible char filtering to `!cfind` command

## Testing

1. Run `!cfind josh`
2. Immediately run `!cfind josh` again
3. Both should return "252 in titles..." (or similar valid result)
4. Check logs - second request should show filtered args

## References

- Twitch IRC documentation doesn't officially document this behavior
- U+034F: https://www.compart.com/en/unicode/U+034F
- Zero-width characters: https://en.wikipedia.org/wiki/Zero-width_space
