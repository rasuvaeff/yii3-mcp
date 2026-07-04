---
name: commit-message
title: Commit message writer
description: Writes a conventional commit message for a staged diff
arguments:
  - name: diff
    description: The staged diff to describe
    required: true
  - scope
---
Write a conventional commit message{{scope}} for the following diff:

{{diff}}
