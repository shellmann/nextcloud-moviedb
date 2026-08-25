<?php

declare(strict_types=1);

/**
 * Mock/stub classes for Nextcloud OCP interfaces
 *
 * This file provides minimal implementations of Nextcloud classes
 * to allow running unit tests in standalone mode without a full
 * Nextcloud installation.
 */

namespace OCP {
    interface IRequest {
        public function getParam(string $key, $default = null);
        public function getParams(): array;
    }
    interface IUser {
        public function getUID(): ?string;
    }
    interface IUserSession {
        public function getUser(): ?IUser;
    }
    interface IConfig {}
}

namespace OCP\AppFramework {
    class App {
        public function __construct(string $appName, array $urlParams = []) {}
    }

    class Controller {
        protected $request;
        protected $appName;

        public function __construct(string $appName, $request) {
            $this->appName = $appName;
            $this->request = $request;
        }
    }

    class Http {
        public const STATUS_OK = 200;
        public const STATUS_CREATED = 201;
        public const STATUS_BAD_REQUEST = 400;
        public const STATUS_UNAUTHORIZED = 401;
        public const STATUS_NOT_FOUND = 404;
        public const STATUS_CONFLICT = 409;
        public const STATUS_INTERNAL_SERVER_ERROR = 500;
    }
}

namespace OCP\AppFramework\Http {
    class Response {
        private $status = 200;

        public function getStatus(): int {
            return $this->status;
        }

        public function setStatus(int $status): void {
            $this->status = $status;
        }
    }

    class JSONResponse extends Response {
        private $data;

        public function __construct($data = [], int $statusCode = 200) {
            $this->data = $data;
            $this->setStatus($statusCode);
        }

        public function getData() {
            return $this->data;
        }
    }

    class DataDownloadResponse extends Response {}

    class ContentSecurityPolicy {}

    class TemplateResponse extends Response {}

    class Http {
        public const STATUS_OK = 200;
        public const STATUS_CREATED = 201;
        public const STATUS_BAD_REQUEST = 400;
        public const STATUS_UNAUTHORIZED = 401;
        public const STATUS_NOT_FOUND = 404;
        public const STATUS_CONFLICT = 409;
        public const STATUS_INTERNAL_SERVER_ERROR = 500;
    }
}

namespace OCP\AppFramework\Http\Attribute {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoAdminRequired {}

    #[\Attribute(\Attribute::TARGET_METHOD)]
    class NoCSRFRequired {}
}

namespace OCP {
    interface IDBConnection {
        public function getQueryBuilder(): mixed;
        public function escapeLikeParameter(string $param): string;
    }
}

namespace OCP\DB\QueryBuilder {
    interface IQueryBuilder {
        public const PARAM_STR = 2;
        public const PARAM_INT = 1;
        public const PARAM_BOOL = 5;
        public const PARAM_STR_ARRAY = 102;
        public const PARAM_INT_ARRAY = 101;
        public function select(...$selects): static;
        public function addSelect(...$selects): static;
        public function from(string $table, ?string $alias = null): static;
        public function where(string $condition): static;
        public function andWhere(string $condition): static;
        public function orWhere(string $condition): static;
        public function orderBy(string $sort, ?string $order = null): static;
        public function setMaxResults(?int $maxResults): static;
        public function setFirstResult(int $firstResult): static;
        public function leftJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static;
        public function innerJoin(string $fromAlias, string $join, string $alias, ?string $condition = null): static;
        public function groupBy(...$groupBys): static;
        public function selectAlias(mixed $select, string $alias): static;
        public function selectDistinct(string $select): static;
        public function createNamedParameter(mixed $value, int $type = 2, ?string $placeHolder = null): string;
        public function createFunction(string $call): string;
        public function executeQuery(): mixed;
        public function getSQL(): string;
        public function func(): mixed;
        public function expr(): mixed;
    }
}

namespace OCP\AppFramework\Db {
    class DoesNotExistException extends \Exception {}

    class Entity {
        protected $id;
        private $_fieldTypes = [];

        public function getId(): ?int {
            return $this->id;
        }

        public function setId(int $id): void {
            $this->id = $id;
        }

        protected function addType(string $name, string $type): void {
            $this->_fieldTypes[$name] = $type;
        }

        // Magic getter/setter support
        public function __call($methodName, $args) {
            if (strpos($methodName, 'set') === 0) {
                $property = lcfirst(substr($methodName, 3));
                $value = $args[0] ?? null;

                // Handle JSON type conversion
                if (isset($this->_fieldTypes[$property]) && $this->_fieldTypes[$property] === 'json') {
                    // If it's a string, decode it; if it's an array, keep it as is
                    if (is_string($value)) {
                        $this->$property = json_decode($value, true);
                    } else {
                        $this->$property = $value;
                    }
                } else {
                    $this->$property = $value;
                }
                return;
            }

            if (strpos($methodName, 'get') === 0) {
                $property = lcfirst(substr($methodName, 3));
                return $this->$property ?? null;
            }

            throw new \BadMethodCallException("Method $methodName does not exist");
        }
    }

    abstract class QBMapper {
        protected $db;
        protected $tableName;
        protected $entityClass;

        public function __construct($db, string $tableName, ?string $entityClass = null) {
            $this->db = $db;
            $this->tableName = $tableName;
            $this->entityClass = $entityClass;
        }

        public function getTableName(): string {
            return $this->tableName;
        }

        public function insert(Entity $entity): Entity {
            return $entity;
        }

        public function update(Entity $entity): Entity {
            return $entity;
        }

        public function delete(Entity $entity): Entity {
            return $entity;
        }

        protected function findEntity($qb): Entity {
            throw new DoesNotExistException('stub: findEntity not implemented');
        }

        protected function findEntities($qb): array {
            return [];
        }

        abstract public function find(int $id, ?string $userId = null);
    }
}

namespace Psr\Log {
    interface LoggerInterface {
        public function emergency($message, array $context = []);
        public function alert($message, array $context = []);
        public function critical($message, array $context = []);
        public function error($message, array $context = []);
        public function warning($message, array $context = []);
        public function notice($message, array $context = []);
        public function info($message, array $context = []);
        public function debug($message, array $context = []);
        public function log($level, $message, array $context = []);
    }
}

namespace OCP {
    class Util {
        public static function addScript(string $app, string $script): void {}
        public static function addStyle(string $app, string $style): void {}
    }
}

namespace OCP\AppFramework\Bootstrap {
    interface IBootstrap {
        public function register(IRegistrationContext $context): void;
        public function boot(IBootContext $context): void;
    }
    interface IRegistrationContext {}
    interface IBootContext {}
}
