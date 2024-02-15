<?php

namespace Trulyao\Phlo;

use Exception;


class Config
{
	public static function load(string $path): bool
	{
		try {
			$file_env = self::loadFromFile($path);
			$sy_env = self::loadFromSystem();

			$env = array_merge($file_env, $sy_env);
			$_ENV = $env;
			return true;
		} catch (Exception) {
			return false;
		}
	}

	public static function get(string $key, string $default = null): string | null
	{
		return $_ENV[$key] ?? $default;
	}

	private static function loadFromSystem(): array
	{
		return $_ENV;
	}

	private static function loadFromFile(string $path = ".env", $must_exist = false): array
	{
		$env_file = str_ends_with($path, '.env') ? $path : $path . '/.env';
		if (!file_exists($env_file)) {
			if ($must_exist) {
				throw new Exception("Env file not found at $env_file");
			}

			return [];
		}

		$env_file = fopen($env_file, 'r');
		$env = [];

		while (!feof($env_file)) {
			$line = fgets($env_file);
			$line = trim($line);

			if (empty($line)) {
				continue;
			}

			$env[] = $line;
		}

		$env = array_filter(
			$env,
			function ($item) {
				return !str_starts_with($item, '#');
			}
		);

		foreach ($env as $idx => $var) {
			$parts = explode('=', $var);
			$key = $parts[0] ?? "";
			$key = trim($key);
			$value = trim($parts[1] ?? "");
			unset($env[$idx]);
			$env[$key] = $value;
		}

		return $env;
	}
}
