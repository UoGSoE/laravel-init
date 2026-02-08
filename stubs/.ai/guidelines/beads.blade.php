
# Beads (`bd`) - Issue Tracker Quick Reference

Beads is a lightweight CLI issue tracker with first-class dependency support. Use it instead of TodoWrite for tracking work across sessions.

## Essential Commands

### View Issues
```bash
bd status              # Overview: counts by status
bd list                # List open issues (default limit 50)
bd list --all          # Include closed issues
bd list -l "label"     # Filter by label
bd list --parent ID    # Show children of an epic
bd ready               # Show issues with no blockers (ready to work on)
bd show <id>           # Full details of an issue
bd graph <id>          # Visual dependency graph
```

### Create Issues
```bash
bd create "Title" -d "Description"                    # Basic task
bd create "Title" --type=feature -d "Description"     # Feature
bd create "Title" --type=bug -d "Description"         # Bug
bd create "Title" --type=epic -d "Description"        # Epic (group of issues)
bd create "Title" --parent=<epic-id>                  # Child of an epic
bd create "Title" -l "label1,label2"                  # With labels
bd create "Title" -p P1                               # With priority (P0-P4, P0=highest)
```

### Update Issues
```bash
bd update <id> -s in_progress     # Start working
bd update <id> -s open            # Back to open
bd update <id> --title "New"      # Change title
bd update <id> -d "New desc"      # Change description
bd update <id> --add-label "foo"  # Add label
bd update <id> -p P1              # Change priority
bd update <id> --claim            # Claim issue (assigns to you + in_progress)
```

### Close Issues
```bash
bd close <id>                     # Close an issue
bd close <id> -r "Reason"         # Close with reason
bd close <id> --suggest-next      # Close and show newly unblocked issues
bd reopen <id>                    # Reopen a closed issue
```

### Dependencies
```bash
bd dep <blocker-id> --blocks <blocked-id>   # A blocks B
bd dep add <blocked-id> <blocker-id>        # Same as above
bd dep list <id>                            # Show dependencies
bd dep remove <blocked-id> <blocker-id>     # Remove dependency
bd dep tree <id>                            # Show dependency tree
```

## Issue Types
- `task` (default) - General work item
- `feature` - New functionality
- `bug` - Something broken
- `epic` - Container for related issues
- `chore` - Maintenance work

## Priorities
- `P0` - Critical/urgent
- `P1` - High priority
- `P2` - Normal (default)
- `P3` - Low priority
- `P4` - Nice to have

## Workflow Pattern

1. **Starting a session**: Run `bd ready` to see what's unblocked
2. **Pick work**: `bd update <id> --claim` to claim an issue
3. **Check details**: `bd show <id>` for full context
4. **Work**: Try to complete the task
5. **User QA Test**: Stop and ask the user for to test/check the work
6. **Complete work**: `bd close <id> --suggest-next` to close and see what's newly unblocked
7. **End of session**: `bd status` to see overall state

## Hierarchical IDs

When creating children of an epic, bd auto-generates hierarchical IDs:
- Epic: `wcap-qj4`
- Children: `wcap-qj4.1`, `wcap-qj4.2`, etc.

## Tips

- Use `bd list --long` for detailed multi-line output
- Use `bd search "keyword"` to search issue text
- Labels are useful for categorisation: `nice-to-have`, `blocked-external`, etc.
- The `--suggest-next` flag on close helps maintain flow

## Finally

**NEVER** close an issue without checking the user is happy with the work. This is a critical part of the workflow.


