<?php

declare(strict_types=1);

namespace Phlo\Core;

class Runner
{
	private Context $ctx;
	private Rule $rule;

	public function __construct(Context &$ctx, Rule &$rule)
	{
		$this->ctx = $ctx;
		$this->rule = $rule;
	}

	public function run(): never
	{
		match ($this->rule->rule_type) {
			RuleType::API => $this->serveApi(),
			RuleType::STATIC => $this->serveStatic(),
			RuleType::REDIRECT => $this->serveRedirect(),
			RuleType::STICKY => $this->serveSticky(),
		};
	}

	private function serveApi(): never
	{
		$accepted_mime_types = $this->getMimeTypesAsString();
		$this->setCommonHeaders($accepted_mime_types);

		$resources = $this->getRequestResources();
		if (!$resources) {
			$this->handleAPIRuleNotFound();
		}

		$this->ctx->setParams($resources['params'] ?? []);
		$file = "{$resources['dir']}/{$resources['file']}";
		if (!is_file($file)) {
			$this->handleAPIRuleNotFound();
		}

		$this->executeFolderScopedMiddleware($resources['dir'] ?? "");
		require_once $file;
		$this->executeFileScopedMiddleware();
		$this->executeAPIMethodHandler();
		exit;
	}


	private function serveStatic(): never
	{
		$accepted_mime_types = $this->getMimeTypesAsString();
		$this->setCommonHeaders($accepted_mime_types);

		$resources = $this->getRequestResources();
		if (!$resources) {
			http_response_code(404);

			$not_found_file = "{$this->rule->target}/404.html";
			if (is_file($not_found_file)) {
				header("Content-Type: text/html; charset=utf-8");
				readfile($not_found_file);
			}

			exit;
		}

		$this->executeFolderScopedMiddleware($resources['dir'] ?? "");
		$file = "{$resources['dir']}/{$resources['file']}";
		$mime_type = self::getMimeTypeFromPath($resources['file'], $this->rule->rule_type);
		header("Content-Type: $mime_type");

		// make the ctx available to the file
		$ctx = $this->ctx;

		require_once $file;
		exit;
	}


	private function serveRedirect(): never
	{
		header("Location: " . $this->rule->target, true, 301);
		exit;
	}

	private function serveSticky(): never
	{
		$accepted_mime_types = $this->getMimeTypesAsString();
		$this->setCommonHeaders($accepted_mime_types);


		if (!is_file($this->rule->target)) {
			http_response_code(404);
			$not_found_file = "{$this->rule->target}/404.html";
			if (is_file($not_found_file)) {
				header("Content-Type: text/html; charset=utf-8");
				readfile($not_found_file);
			}
			exit;
		}

		$mime_type = self::getMimeTypeFromPath($this->rule->target, $this->rule->rule_type);
		header("Content-Type: {$mime_type}");
		readfile($this->rule->target);
		exit;
	}

	private function getRequestResources(): array | null
	{
		$resource_dir = $this->rule->target;
		$resource_file = null;
		$params = [];

		foreach ($this->ctx->path_parts as $idx => $resource) {
			// check if the folder exists
			if (is_dir("{$resource_dir}/{$resource}")) {
				$resource_dir .= "/{$resource}";
				continue;
			}

			// check if the folder contains a PHP file with the name of the resource requested and stop there
			if (is_file("{$resource_dir}/{$resource}.php")) {
				$resource_file = "{$resource}.php";
				break;
			}

			// check if the folder contains an HTML file with the name of the resource requested and stop there
			if (is_file("{$resource_dir}/{$resource}.html")) {
				$resource_file = "{$resource}.html";
				break;
			}

			// check for an index.php in that folder
			if (is_file("{$resource_dir}/index.php")) {
				$resource_file = "index.php";
				break;
			}

			// for static resources, check if the file exists
			if (is_file("{$resource_dir}/{$resource}")) {
				$resource_file = $resource;
				break;
			}

			// go through every file and folder in the folder and check if it matches the format [param].php where param could be anything
			$files = scandir($resource_dir);
			foreach ($files as $file) {
				if (str_starts_with($file, "[") && str_ends_with($file, "].php")) {
					$resource_file = $file;
					$key = str_replace(["[", "]"], "", str_replace(".php", "", $file));
					$params[$key] = $resource;

					// make sure this is the last iteration before breaking
					if ($idx === count($this->ctx->path_parts) - 1) {
						break;
					}
					continue;
				}
				if (str_starts_with($file, "[") && str_ends_with($file, "]")) {
					$resource_dir .= "/{$file}";
					$key = str_replace(["[", "]"], "", $file);
					$params[$key] = $resource;

					// make sure this is the last iteration before breaking to prevent running an handler that doesn't match the request
					if ($idx === count($this->ctx->path_parts) - 1) {
						break;
					}
				}
			}
		}

		// if we somehow ended up with no target file, check if it contains an index.php or index.html, this works in a case where we have `/` as the path or the request matches a folder; in that case, we want to go into the folder to find the index file
		if (empty($resource_file)) {
			if (is_file("{$resource_dir}/index.html")) {
				$resource_file = "index.html";
			} elseif (is_file("{$resource_dir}/index.php")) {
				$resource_file = "index.php";
			}
		}

		// make sure it is an exact match by comparing the number of path parts in the request with the number of path parts in the rule (excluding the route prefix)
		// while these could have been all chained together, they are separated into individual variables for readability
		$rule_root_parts_count = count(explode("/", trim("{$this->rule->target}", "/")));
		$abs_resource_file_parts_count = count(explode("/", trim("{$resource_dir}/" . ($resource_file ?? ""), "/")));
		$matched_resource_count = $abs_resource_file_parts_count - $rule_root_parts_count;
		$required_match = count($this->ctx->path_parts) - count(explode("/", $this->rule->prefix ?? ""));
		$is_invalid_match = count($this->ctx->path_parts) !== 0 && $this->rule->rule_type === RuleType::API && $matched_resource_count !== $required_match;

		if (!$resource_file || $is_invalid_match) {
			return null;
		}

		return [
			"dir" => $resource_dir,
			"file" => $resource_file,
			"params" => $params,
		];
	}

	private function getMimeTypesAsString(): array
	{
		$accepted_mime_types = array_map(fn ($mime_type) => $mime_type->value, $this->rule->accepted_mime_types ?? [MimeType::JSON]);
		return array_unique($accepted_mime_types);
	}

	private function setCommonHeaders(array $accepted_mime_types): void
	{
		header_remove("X-Powered-By");
		header("Access-Control-Allow-Origin: *");
		header("Access-Control-Allow-Methods: GET, POST");
		header("Access-Control-Allow-Headers: *");
		header("Accept: " . implode(",", $accepted_mime_types));
	}

	private function executeFolderScopedMiddleware(string $target_folder): void
	{
		$middleware_file = "{$target_folder}/_middleware.php";
		if (is_file($middleware_file)) {
			ob_start();
			require_once $middleware_file;
			if (function_exists("_global_init")) {
				_global_init($this->ctx);
			}
			ob_end_clean();
		}
	}

	private function executeFileScopedMiddleware(): void
	{
		if (function_exists("_init")) {
			_init($this->ctx);
		}
	}

	private function executeAPIMethodHandler(): void
	{
		define("GET", "get");
		define("POST", "post");
		define("PUT", "put");
		define("DELETE", "delete");
		define("PATCH", "patch");

		$method = match ($_SERVER['REQUEST_METHOD']) {
			"POST" => POST,
			"GET" => GET,
			"PUT" => PUT,
			"DELETE" => DELETE,
			"PATCH" => PATCH,
			default => null,
		};

		if (!$method) {
			$this->ctx->status(405)->send([
				"ok" => false,
				"message" => "method not allowed",
				"code" => 405,
			]);
		}

		if (function_exists($method)) {
			$method($this->ctx);
			return;
		}

		if (function_exists("any")) {
			any($this->ctx);
			return;
		}


		$this->ctx->status(405)->send([
			"ok" => false,
			"message" => "method not allowed",
			"code" => 405,
		]);
	}

	public static function getMimeTypeFromPath(string $filepath, RuleType $rule_type): string
	{
		$extension = pathinfo($filepath, PATHINFO_EXTENSION);
		// mime_content_type fails on some systems, so we do a manual lookup first and fallback to mime_content_type
		return match ($extension) {
			"aac" => "audio/aac",
			"abw" => "application/x-abiword",
			"apng" => "image/apng",
			"arc" => "application/x-freearc",
			"avif" => "image/avif",
			"avi" => "video/x-msvideo",
			"azw" => "application/vnd.amazon.ebook",
			"bmp" => "image/bmp",
			"bz" => "application/x-bzip",
			"bz2" => "application/x-bzip2",
			"cda" => "application/x-cdf",
			"csh" => "application/x-csh",
			"css" => "text/css",
			"csv" => "text/csv",
			"doc" => "application/msword",
			"docx" => "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
			"eot" => "application/vnd.ms-fontobject",
			"epub" => "application/epub+zip",
			"gz" => "application/gzip",
			"gif" => "image/gif",
			"htc" => "text/x-component",
			"htm" | "html" | "stm" => "text/html",
			"htt" => "text/webviewhtml",
			"ico" => "image/vnd.microsoft.icon",
			"ics" => "text/calendar",
			"jar" => "application/java-archive",
			"jpeg" | "jpg" => "image/jpeg",
			"js" | "mjs" => "text/javascript",
			"json" => "application/json",
			"jsonld" => "application/ld+json",
			"mid" | "midi" => "audio/midi",
			"mht" | "mhtml" | "nws" => "message/rfc822",
			"mp3" => "audio/mpeg",
			"mp4" => "video/mp4",
			"mpeg" | "mpg" | "mpa" | "mpe" | "mp2" | "mpv2" => "video/mpeg",
			"mpkg" => "application/vnd.apple.installer+xml",
			"mov" | "qt" => "video/quicktime",
			"odp" => "application/vnd.oasis.opendocument.presentation",
			"ods" => "application/vnd.oasis.opendocument.spreadsheet",
			"odt" => "application/vnd.oasis.opendocument.text",
			"oga" => "audio/ogg",
			"ogv" => "video/ogg",
			"ogx" => "application/ogg",
			"opus" => "audio/opus",
			"otf" => "font/otf",
			"png" => "image/png",
			"pdf" => "application/pdf",
			"php" => $rule_type === RuleType::API ? "" : "text/html", // in API routes, the `$ctx->send` method is used to control the response type, we don't want to set it to the proper x-httpd-php MIME type here because it will literally send the PHP file to the client; horrible security risk
			"ppt" => "application/vnd.ms-powerpoint",
			"pptx" => "application/vnd.openxmlformats-officedocument.presentationml.presentation",
			"rgb" => "image/x-rgb",
			"rar" => "application/vnd.rar",
			"rtf" => "application/rtf",
			"rtx" => "text/richtext",
			"sh" => "application/x-sh",
			"svg" => "image/svg+xml",
			"tar" => "application/x-tar",
			"tif" | "tiff" => "image/tiff",
			"ts" => "video/mp2t",
			"ttf" => "font/ttf",
			"txt" | "c" | "h" | "bas" => "text/plain",
			"vcf" => "text/vcard",
			"vsd" => "application/vnd.visio",
			"wav" => "audio/wav",
			"weba" => "audio/webm",
			"webm" => "video/webm",
			"webp" => "image/webp",
			"woff" => "font/woff",
			"woff2" => "font/woff2",
			"xhtml" => "application/xhtml+xml",
			"xls" => "application/vnd.ms-excel",
			"xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
			"xml" => "text/xml",
			"xul" => "application/vnd.mozilla.xul+xml",
			"zip" => "application/zip",
			"3gp" => "video/3gpp",
			"3g2" => "video/3gpp2",
			"7z" => "application/x-7z-compressed",
			default => mime_content_type(basename($filepath))
		};
	}

	private function handleAPIRuleNotFound(): never
	{
		header("Content-Type: application/json");
		http_response_code(404);
		if (is_file("{$this->rule->target}/404.json")) {
			require_once "{$this->rule->target}/404.json";
		} else {
			echo "{\"ok\": false,\"message\": \"Cannot {$this->ctx->method} {$this->ctx->uri}\"}";
		}
		exit;
	}
}
