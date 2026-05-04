#!/usr/bin/env python3
"""
镜像源瘦身脚本
功能：删除每个镜像的内页HTML和静态资源，只保留 index.html + config.json
用法：
  python3 clean_mirrors.py                  # 直接执行删除
  python3 clean_mirrors.py --dry-run        # 试运行（只统计，不删除）
"""

import os
import sys
import json
import shutil
import argparse
from pathlib import Path

# ──────────────────────────────────────────────
# 配置
# ──────────────────────────────────────────────
DEFAULT_MIRRORS_DIR = "/www/wwwroot/kelong/kelong.auto/data/mirrors"

# 每个镜像目录里需要删除的子目录
DELETE_SUBDIRS = ["pages", "static"]

# 每个镜像必须保留的文件（不在此列表的根文件也一并删除）
KEEP_FILES = {"index.html", "config.json"}


def format_size(size_bytes: int) -> str:
    """字节数转人类可读格式"""
    if size_bytes >= 1024 ** 3:
        return f"{size_bytes / 1024**3:.2f} GB"
    elif size_bytes >= 1024 ** 2:
        return f"{size_bytes / 1024**2:.1f} MB"
    elif size_bytes >= 1024:
        return f"{size_bytes / 1024:.1f} KB"
    return f"{size_bytes} B"


def get_dir_size(path: str) -> tuple[int, int]:
    """返回 (总字节数, 文件数量)"""
    total_bytes = 0
    total_files = 0
    for root, _, files in os.walk(path):
        for f in files:
            fp = os.path.join(root, f)
            try:
                total_bytes += os.path.getsize(fp)
                total_files += 1
            except OSError:
                pass
    return total_bytes, total_files


def update_config(config_path: str, index_size: int) -> bool:
    """更新 config.json：清空 inner_pages，修正 statistics"""
    try:
        with open(config_path, "r", encoding="utf-8") as f:
            cfg = json.load(f)

        pages = cfg.setdefault("pages", {})
        pages["inner_pages"] = {}
        pages["pages"] = []

        cfg["statistics"] = {
            "total_pages": 1,
            "total_size": index_size,
            "success_rate": "100%"
        }

        with open(config_path, "w", encoding="utf-8") as f:
            json.dump(cfg, f, ensure_ascii=False, indent=4)
        return True
    except Exception as e:
        print(f"    [警告] config.json 更新失败: {e}")
        return False


def process_mirror(mirror_dir: str, dry_run: bool) -> dict:
    """处理单个镜像目录，返回统计信息"""
    result = {
        "mirror_id": os.path.basename(mirror_dir),
        "deleted_bytes": 0,
        "deleted_files": 0,
        "errors": [],
        "skipped_reason": None,
    }

    # 检查 index.html 是否存在
    index_path = os.path.join(mirror_dir, "index.html")
    config_path = os.path.join(mirror_dir, "config.json")

    if not os.path.exists(index_path):
        result["skipped_reason"] = "index.html 不存在，跳过"
        return result

    index_size = os.path.getsize(index_path)

    # 统计 / 删除 pages/ 和 static/
    for subdir_name in DELETE_SUBDIRS:
        subdir = os.path.join(mirror_dir, subdir_name)
        if not os.path.isdir(subdir):
            continue

        size, count = get_dir_size(subdir)
        result["deleted_bytes"] += size
        result["deleted_files"] += count

        if not dry_run:
            try:
                shutil.rmtree(subdir)
            except Exception as e:
                result["errors"].append(f"删除 {subdir_name}/ 失败: {e}")

    # 删除根目录下的多余文件（非 index.html / config.json 的文件）
    for entry in os.scandir(mirror_dir):
        if entry.is_file() and entry.name not in KEEP_FILES:
            size = entry.stat().st_size
            result["deleted_bytes"] += size
            result["deleted_files"] += 1
            if not dry_run:
                try:
                    os.remove(entry.path)
                except Exception as e:
                    result["errors"].append(f"删除文件 {entry.name} 失败: {e}")

    # 更新 config.json
    if not dry_run and os.path.exists(config_path):
        update_config(config_path, index_size)

    return result


def main():
    parser = argparse.ArgumentParser(description="镜像源瘦身：只保留首页，删除内页和静态资源")
    parser.add_argument("--path", default=DEFAULT_MIRRORS_DIR,
                        help=f"镜像目录路径（默认：{DEFAULT_MIRRORS_DIR}）")
    parser.add_argument("--dry-run", action="store_true",
                        help="试运行：只统计不删除")
    args = parser.parse_args()

    mirrors_dir = args.path
    dry_run = args.dry_run

    # 验证目录
    if not os.path.isdir(mirrors_dir):
        print(f"[错误] 目录不存在: {mirrors_dir}")
        sys.exit(1)

    # 获取所有镜像子目录
    mirror_dirs = sorted([
        os.path.join(mirrors_dir, d)
        for d in os.listdir(mirrors_dir)
        if os.path.isdir(os.path.join(mirrors_dir, d))
    ])

    if not mirror_dirs:
        print(f"[提示] 未找到任何镜像目录: {mirrors_dir}")
        sys.exit(0)

    mode_label = "【试运行 - 不会删除任何文件】" if dry_run else "【正式执行 - 将删除文件！】"
    print(f"\n{'='*60}")
    print(f"  镜像源瘦身脚本  {mode_label}")
    print(f"  目标目录: {mirrors_dir}")
    print(f"  镜像数量: {len(mirror_dirs)}")
    print(f"{'='*60}\n")

    # 处理每个镜像
    total_deleted_bytes = 0
    total_deleted_files = 0
    total_errors = 0
    skipped = 0

    for mirror_dir in mirror_dirs:
        result = process_mirror(mirror_dir, dry_run)

        if result["skipped_reason"]:
            print(f"  [跳过] {result['mirror_id']}: {result['skipped_reason']}")
            skipped += 1
            continue

        status = "✓" if not result["errors"] else "!"
        action = "待删除" if dry_run else "已删除"
        print(
            f"  [{status}] {result['mirror_id']}"
            f" | {action}: {format_size(result['deleted_bytes'])}"
            f" ({result['deleted_files']} 个文件)"
        )

        if result["errors"]:
            for err in result["errors"]:
                print(f"        [错误] {err}")
                total_errors += 1

        total_deleted_bytes += result["deleted_bytes"]
        total_deleted_files += result["deleted_files"]

    # 汇总
    print(f"\n{'='*60}")
    print(f"  处理完成")
    print(f"  镜像总数:  {len(mirror_dirs)} 个（跳过 {skipped} 个）")
    print(f"  {'预计释放' if dry_run else '已释放'}空间: {format_size(total_deleted_bytes)}")
    print(f"  {'预计删除' if dry_run else '已删除'}文件: {total_deleted_files} 个")
    if total_errors:
        print(f"  错误数量:  {total_errors} 个（请检查上方日志）")
    print(f"{'='*60}\n")

    if dry_run:
        print("  → 以上为试运行结果，确认无误后直接执行：")
        print(f"     python3 {os.path.abspath(__file__)}\n")


if __name__ == "__main__":
    main()
