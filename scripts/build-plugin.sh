#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
build_root="${project_dir}/dist"
plugin_dir="${build_root}/avpvh-audio-transcriptie"

mkdir -p "${plugin_dir}/assets" "${plugin_dir}/includes" "${plugin_dir}/languages"
cp "${project_dir}/avpvh-audio-transcriptie.php" "${plugin_dir}/"
cp "${project_dir}/readme.txt" "${plugin_dir}/"
cp "${project_dir}/LICENSE" "${plugin_dir}/"
cp "${project_dir}/assets/admin.css" "${plugin_dir}/assets/"
cp "${project_dir}/assets/admin.js" "${plugin_dir}/assets/"
cp "${project_dir}/includes/class-avpvh-at-plugin.php" "${plugin_dir}/includes/"

if command -v zip >/dev/null 2>&1; then
	(
		cd "${build_root}"
		zip -q -r avpvh-audio-transcriptie.zip avpvh-audio-transcriptie
	)
fi

printf 'Distribution built in %s\n' "${build_root}"
