# Archive Cleanup Instructions

## Problem
Over time, the archive accumulated 360 files, but 305 were duplicates where only the date changed (no recipe content changes).

## Solution
The `cleanup_duplicates.php` script identifies and removes duplicate archives based on table content comparison.

## How to Run the Cleanup

### Option 1: Manual Cleanup on gh-pages (Recommended)

```bash
# 1. Clone the repository
git clone <your-repo-url>
cd Birmingham-Recipe-Extractor

# 2. Switch to gh-pages branch
git checkout gh-pages

# 3. Copy the cleanup script from develop
git show develop:cleanup_duplicates.php > cleanup_duplicates.php

# 4. Run the cleanup script (it will ask for confirmation)
php cleanup_duplicates.php

# 5. Review the changes and commit
git add -A
git commit -m "Clean up duplicate archive files"

# 6. Push to gh-pages (requires permissions)
git push origin gh-pages
```

### Option 2: Integrate into Workflow (One-time Run)

Add a one-time cleanup step to `.github/workflows/deploy.yml`:

```yaml
# After Step 4: Restore preserved files to output
- name: One-time archive cleanup (remove after first run)
  run: |
    if [ -f "cleanup_duplicates.php" ]; then
      cd output
      echo 'y' | php ../cleanup_duplicates.php
      cd ..
    fi
```

Then **remove this step** after the first successful deployment.

## What the Script Does

1. Scans all files in `archive/*-recipes.html`
2. Extracts table content from each file
3. Groups files by content hash (MD5)
4. For duplicate groups:
   - Keeps the **oldest** file (first occurrence)
   - Marks newer duplicates for deletion
5. Asks for confirmation before deleting
6. Regenerates the archive index

## Results

The cleanup identified:
- **360 total archive files**
- **55 unique recipe versions**
- **305 duplicates** (same content, different dates)

Most notable duplicate runs:
- July 1 - September 19, 2025: 81 consecutive days
- November 13 - January 7: 56 consecutive days
- March 18 - April 2: 16 consecutive days

## Prevention

The fixes in this branch prevent future duplicates:
- ✅ Compare content **before** copying to `index.html`
- ✅ Only update when recipes actually change
- ✅ Delete redundant files when no changes detected
- ✅ Better logging shows what changed

Once merged to develop, no new duplicates will be created.
