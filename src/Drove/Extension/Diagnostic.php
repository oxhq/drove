<?php

declare(strict_types=1);

namespace Drove\Extension;

enum Diagnostic: string
{
    case DiscoveryIo = 'DROVE_EXT_DISCOVERY_IO';
    case DiscoveryJson = 'DROVE_EXT_DISCOVERY_JSON';
    case ManifestInvalid = 'DROVE_EXT_MANIFEST_INVALID';
    case ComposerPluginForbidden = 'DROVE_EXT_COMPOSER_PLUGIN_FORBIDDEN';
    case AutoloadFilesForbidden = 'DROVE_EXT_AUTOLOAD_FILES_FORBIDDEN';
    case ApiIncompatible = 'DROVE_EXT_API_INCOMPATIBLE';
    case DuplicateId = 'DROVE_EXT_DUPLICATE_ID';
    case ConfigurationInvalid = 'DROVE_EXT_CONFIGURATION_INVALID';
    case EntrypointInvalid = 'DROVE_EXT_ENTRYPOINT_INVALID';
    case DuplicateContribution = 'DROVE_EXT_DUPLICATE_CONTRIBUTION';
    case LateRegistration = 'DROVE_EXT_LATE_REGISTRATION';
    case UntypedContribution = 'DROVE_EXT_UNTYPED_CONTRIBUTION';
    case RegistrationFailed = 'DROVE_EXT_REGISTRATION_FAILED';
    case MissingContribution = 'DROVE_EXT_MISSING_CONTRIBUTION';
    case UnknownOwner = 'DROVE_EXT_UNKNOWN_OWNER';
    case UnknownContribution = 'DROVE_EXT_UNKNOWN_CONTRIBUTION';
    case ConfigurationMissing = 'DROVE_EXT_CONFIGURATION_MISSING';
    case ContributionFailed = 'DROVE_EXT_CONTRIBUTION_FAILED';
}
