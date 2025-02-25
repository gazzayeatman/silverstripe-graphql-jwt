<?php declare(strict_types=1);

namespace Firesphere\GraphQLJWT\Authentication;

use Exception;
use LogicException;
use DateTimeImmutable;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer;
use OutOfBoundsException;
use BadMethodCallException;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa;
use Lcobucci\JWT\Signer\Hmac;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\ValidationData;
use SilverStripe\Security\Member;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Authenticator;
use SilverStripe\ORM\ValidationException;
use Firesphere\GraphQLJWT\Model\JWTRecord;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBDatetime;
use Firesphere\GraphQLJWT\Types\TokenStatusEnum;
use Firesphere\GraphQLJWT\Extensions\MemberExtension;
use Firesphere\GraphQLJWT\Helpers\MemberTokenGenerator;
use SilverStripe\Security\MemberAuthenticator\MemberAuthenticator;

class JWTAuthenticator extends MemberAuthenticator
{
    use Injectable;
    use Configurable;
    use MemberTokenGenerator;

    const JWT_SIGNER_KEY = 'JWT_SIGNER_KEY';

    const JWT_KEY_PASSWORD = 'JWT_KEY_PASSWORD';

    const JWT_PUBLIC_KEY = 'JWT_PUBLIC_KEY';

    /**
     * Key is RSA public/private pair
     */
    const RSA = 'RSA';

    /**
     * Key is RSA public/private pair, with password enabled
     */
    const RSA_PASSWORD = 'RSA_PASSWORD';

    /**
     * Key is HMAC string
     */
    const HMAC = 'HMAC';

    /**
     * Set to true to allow anonymous JWT tokens (no member record / email / password)
     *
     * @config
     * @var bool
     */
    private static $anonymous_allowed = false;

    /**
     * @config
     * @var int
     */
    private static $nbf_time = 0;

    /**
     * Expires after 1 hour
     *
     * @config
     * @var int
     */
    private static $nbf_expiration = 3600;

    /**
     * Token can be refreshed within 7 days
     *
     * @config
     * @var int
     */
    private static $nbf_refresh_expiration = 604800;

    /**
     * Keys are one of:
     *   - public / private RSA pair files
     *   - public / private RSA pair files, password protected private key
     *   - private HMAC string
     *
     * @return string
     */
    protected function getKeyType(): string
    {
        $signerKey = $this->getEnv(self::JWT_SIGNER_KEY);
        $path = $this->resolvePath($signerKey);
        if (!$path) {
            return self::HMAC;
        }
        if ($this->getEnv(self::JWT_KEY_PASSWORD, null)) {
            return self::RSA_PASSWORD;
        }
        return self::RSA;
    }

    /**
     * @return Signer
     */
    protected function getSigner(): Signer
    {
        switch ($this->getKeyType()) {
            case self::HMAC:
                return new Hmac\Sha256();
            case self::RSA:
            case self::RSA_PASSWORD:
            default:
                return new Rsa\Sha256();
        }
    }

    /**
     * Get private key used to generate JWT tokens
     *
     * @return Key
     */
    protected function getPrivateKey(): Key
    {
        // Note: Only private key has password enabled
        $password = $this->getEnv(self::JWT_KEY_PASSWORD, null);
        return $this->makeKey(self::JWT_SIGNER_KEY, $password);
    }

    /**
     * Get public key used to validate JWT tokens
     *
     * @return Key
     * @throws LogicException
     */
    protected function getPublicKey(): Key
    {
        switch ($this->getKeyType()) {
            case self::HMAC:
                // If signer key is a HMAC string instead of a path, public key == private key
                return $this->getPrivateKey();
            default:
                // If signer key is a path to RSA token, then we require a separate public key path
                return $this->makeKey(self::JWT_PUBLIC_KEY);
        }
    }

    /**
     * Construct a new key from the named config variable
     *
     * @param string $name Key name
     * @param string|null $password Optional password
     * @return Key
     */
    private function makeKey(string $name, string $password = null): Key
    {
        $key = $this->getEnv($name);
        $path = $this->resolvePath($key);

        // String key
        if (empty($path)) {
            return InMemory::plainText($key);
        }

        // Build key from path
        return InMemory::file('file://' . $path, $password);
    }

    /**
     * JWT is stateless, therefore, we don't support anything but login
     *
     * @return int
     */
    public function supportedServices(): int
    {
        return Authenticator::LOGIN;
    }

    /**
     * @param array $data
     * @param HTTPRequest $request
     * @param ValidationResult|null $result
     * @return Member|null
     * @throws OutOfBoundsException
     * @throws BadMethodCallException
     * @throws Exception
     */
    public function authenticate(array $data, HTTPRequest $request, ValidationResult &$result = null): ?Member
    {
        if (!$result) {
            $result = new ValidationResult();
        }
        $token = $data['token'];

        /** @var JWTRecord $record */
        list($record, $status) = $this->validateToken($token, $request);

        // Report success!
        if ($status === TokenStatusEnum::STATUS_OK) {
            return $record->Member();
        }

        // Add errors to result
        $result->addError(
            $this->getErrorMessage($status),
            ValidationResult::TYPE_ERROR,
            $status
        );
        return null;
    }

    /**
     * Generate a new JWT token for a given request, and optional (if anonymous_allowed) user
     *
     * @param HTTPRequest $request
     * @param Member|MemberExtension $member
     * @return Token
     * @throws ValidationException
     */
    public function generateToken(HTTPRequest $request, Member $member): Token
    {
        $config = static::config();
        $uniqueID = uniqid($this->getEnv('JWT_PREFIX', ''), true);

        // Create new record
        $record = new JWTRecord();
        $record->UID = $uniqueID;
        $record->UserAgent = $request->getHeader('User-Agent');
        $member->AuthTokens()->add($record);
        if (!$record->isInDB()) {
            $record->write();
        }

        $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText('testing'));
        // Create builder for this record
        $builder = $config->builder();
        $now = $now = new DateTimeImmutable();
        $token = $builder
            // Configures the issuer (iss claim)
            ->issuedBy($request->getHeader('Origin'))
            // Configures the audience (aud claim)
            ->permittedFor(Director::absoluteBaseURL())
            // Configures the id (jti claim), replicating as a header item
            ->identifiedBy($uniqueID, true)
            // Configures the time that the token was issue (iat claim)
            ->issuedAt($now)
            // Configures the time that the token can be used (nbf claim)
            ->canOnlyBeUsedAfter($now->modify('+1 minute'))
            // Configures the expiration time of the token (nbf claim)
            ->expiresAt($now->modify('+1 hour'))
            // Configures a new claim, called "rid"
            ->withClaim('rid', $record->ID)
            // Set the subject, which is the member
            ->relatedTo($member->getJWTData());

        return $token->getToken($this->getSigner(), $this->getPrivateKey());
    }

    /**
     * @param string $token
     * @param HTTPRequest $request
     * @return array|null Array with JWTRecord and int status (STATUS_*)
     * @throws BadMethodCallException|Exception
     */
    public function validateToken(?string $token, HTTPrequest $request): array
    {
        // Parse token
        $parsedToken = $this->parseToken($token);
        if (!$parsedToken) {
            return [null, TokenStatusEnum::STATUS_INVALID];
        }

        // Find local record for this token
        /** @var JWTRecord $record */
        $record = JWTRecord::get()->byID($parsedToken->getClaim('rid'));
        if (!$record) {
            return [null, TokenStatusEnum::STATUS_INVALID];
        }

        // Verified and valid = ok!
        $valid = $this->validateParsedToken($parsedToken, $request, $record);
        if ($valid) {
            return [$record, TokenStatusEnum::STATUS_OK];
        }

        // If the token is invalid, but not because it has expired, fail
        $now = new DateTimeImmutable(DBDatetime::now()->getValue());
        if (!$parsedToken->isExpired($now)) {
            return [$record, TokenStatusEnum::STATUS_INVALID];
        }

        // If expired, check if it can be renewed
        $canReniew = $this->canTokenBeRenewed($parsedToken);
        if ($canReniew) {
            return [$record, TokenStatusEnum::STATUS_EXPIRED];
        }

        // If expired and cannot be renewed, it's dead
        return [$record, TokenStatusEnum::STATUS_DEAD];
    }

    /**
     * Parse a string into a token
     *
     * @param string|null $token
     * @return Token|null
     */
    protected function parseToken(?string $token): ?Token
    {
        // Ensure token given at all
        if (!$token) {
            return null;
        }

        try {
            // Verify parsed token matches signer
            $parser = new Parser();
            $parsedToken = $parser->parse($token);
        } catch (Exception $ex) {
            // Un-parsable tokens are invalid
            return null;
        }

        // Verify this token with configured keys
        $verified = $parsedToken->verify($this->getSigner(), $this->getPublicKey());
        return $verified ? $parsedToken : null;
    }

    /**
     * Determine if the given token is current, given the context of the current request
     *
     * @param Token $parsedToken
     * @param HTTPRequest $request
     * @param JWTRecord $record
     * @return bool
     */
    protected function validateParsedToken(Token $parsedToken, HTTPrequest $request, JWTRecord $record): bool
    {
        $now = DBDatetime::now()->getTimestamp();
        $validator = new ValidationData();
        $validator->setIssuer($request->getHeader('Origin'));
        $validator->setAudience(Director::absoluteBaseURL());
        $validator->setId($record->UID);
        $validator->setCurrentTime($now);
        return $parsedToken->validate($validator);
    }

    /**
     * Check if the given token can be renewed
     *
     * @param Token $parsedToken
     * @return bool
     */
    protected function canTokenBeRenewed(Token $parsedToken): bool
    {
        $renewBefore = $parsedToken->getClaim('rexp');
        $now = DBDatetime::now()->getTimestamp();
        return $renewBefore > $now;
    }

    /**
     * Return an absolute path from a relative one
     * If the path doesn't exist, returns null
     *
     * @param string $path
     * @param string $base
     * @return string|null
     */
    protected function resolvePath(string $path, string $base = BASE_PATH): ?string
    {
        if (strstr($path, '/') !== 0) {
            $path = $base . '/' . $path;
        }
        return realpath($path) ?: null;
    }


    /**
     * Get an environment value. If $default is not set and the environment isn't set either this will error.
     *
     * @param string $key
     * @param string|null $default
     * @return string|null
     * @throws LogicException Error if environment variable is required, but not configured
     */
    protected function getEnv(string $key, $default = null): ?string
    {
        $value = Environment::getEnv($key);
        if ($value) {
            return $value;
        }
        if (func_num_args() === 1) {
            throw new LogicException("Required environment variable {$key} not set");
        }
        return $default;
    }
}
