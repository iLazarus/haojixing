---
description: "Use when the user asks to commit with Git. Automatically create a concise commit message and perform a non-interactive git commit workflow."
name: "Auto Git Commit Workflow"
---
# Git 自动提交规则

- 当且仅当用户明确表示“需要提交/请提交/做 git commit”时，自动执行提交流程。
- 提交前先检查工作区变更，优先只提交与当前任务相关的文件，避免混入无关改动。
- 默认使用非交互式 Git 命令执行提交，不使用交互式控制台流程。
- 自动生成简明扼要的提交信息，准确概括变更目的与范围，避免冗长描述。
- 默认强制使用 Conventional Commits（如 feat:, fix:, refactor:, docs:, chore:）。
- 若用户要求指定提交信息或调整提交规范，以用户要求为准。
- 若存在明显冲突风险（如合并冲突、未解决冲突标记），先提示风险并停止自动提交。
- 未经用户明确要求，不执行推送、强推、变基或改写历史操作。

## 提交信息要求

- 一句话总结核心变更，优先使用动词开头。
- 使用 Conventional Commits 前缀 + 简短主题，主题聚焦单一目标。
- 控制长度，避免包含无关细节。
- 涉及多个模块时，优先突出本次任务最关键的一个目标。
