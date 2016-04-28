<?php

namespace Netgen\Bundle\EzSocialConnectBundle\OAuth;

use eZ\Publish\API\Repository\Repository;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use Netgen\Bundle\EzSocialConnectBundle\Entity\OAuthEz;
use Netgen\Bundle\EzSocialConnectBundle\Helper\SocialLoginHelper;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use eZ\Publish\Core\MVC\Symfony\Security\User\Provider as BaseUserProvider;

class eZUserProvider extends BaseUserProvider implements OAuthAwareUserProviderInterface
{
    /**
     * @var \Netgen\Bundle\EzSocialConnectBundle\Helper\SocialLoginHelper
     */
    protected $loginHelper;

    /**
     * eZUserProvider constructor.
     *
     * @param \eZ\Publish\API\Repository\Repository $repository
     * @param \Netgen\Bundle\EzSocialConnectBundle\Helper\SocialLoginHelper $loginHelper
     */
    public function __construct(Repository $repository, SocialLoginHelper $loginHelper)
    {
        parent::__construct($repository);
        $this->loginHelper = $loginHelper;
    }

    /**
     * Loads the user by a given UserResponseInterface object.
     * If no eZ user is found those credentials, a real eZ User content object is generated.
     *
     * @param UserResponseInterface $response

     * @return OAuthEzUser
     *
     * @throws UsernameNotFoundException if the user is not found
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        $OAuthEzUser = $this->generateOAuthEzUser($response);
        $OAuthEzUserEntity = $this->loginHelper->loadFromTable($OAuthEzUser);

        if ($OAuthEzUserEntity instanceof OAuthEz) {
            try {
                // If the user account is linked to the external table, fill in available fields
                $ezUserId = $OAuthEzUserEntity->getEzUserId();
                $userContentObject = $this->loginHelper->loadEzUserById($ezUserId);

                $imageLink = $OAuthEzUser->getImageLink();
                if (!empty($imageLink)) {
                    $this->loginHelper->addProfileImage($userContentObject, $imageLink);
                }

                // If the email is 'localhost.local', we did not fetch it remotely from the OAuth resource provider
                if (
                    $OAuthEzUser->getEmail() !== $userContentObject->email &&
                    0 !== strpos(strrev($OAuthEzUser->getEmail()), 'lacol.tsohlacol')
                ) {
                    $this->loginHelper->updateUserFields($userContentObject, array('email' => $OAuthEzUser->getEmail()));
                }

                return $this->loadUserByUsername($userContentObject->login);
            } catch (\eZ\Publish\API\Repository\Exceptions\NotFoundException $e) {

                // Something went wrong - data is in the table, but the user does not exist
                // Remove faulty data and fall back to creating a new user
                $this->loginHelper->removeFromTable($OAuthEzUserEntity);
            }
        }

        // Otherwise, try to load the existing, linked user
        try {
            $user = $this->loadUserByUsername($OAuthEzUser->getUsername());

        // If no users are found, create one and link them
        } catch (UsernameNotFoundException $e) {
            $user = $this->loginHelper->createEzUser($OAuthEzUser);
            $this->loginHelper->addToTable($user, $OAuthEzUser);
        }

        return $this->loadUserByUsername($user->login);
    }

    /**
     * Generates an OAuthEzUser object from the OAuth response.
     *
     * This is an intermediary object used to generate Ez Users if none exist with those OAuth credentials.
     *
     * @param \HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface $response
     *
     * @return \Netgen\Bundle\EzSocialConnectBundle\OAuth\OAuthEzUser
     */
    protected function generateOAuthEzUser(UserResponseInterface $response)
    {
        $userId = $response->getUsername();
        $uniqueLogin = $response->getNickname().'-'.$userId;

        $OAuthEzUser = new OAuthEzUser($uniqueLogin, $userId);

        $username = $this->getUsername($response);
        $OAuthEzUser->setFirstName($username['firstName']);
        $OAuthEzUser->setLastName($username['lastName']);

        if (null === $response->getEmail()) {
            $email = md5('socialbundle'.$response->getResourceOwner()->getName().$userId).'@localhost.local';
        } else {
            $email = $response->getEmail();
        }
        $OAuthEzUser->setEmail($email);

        $OAuthEzUser->setResourceOwnerName($response->getResourceOwner()->getName());

        if ($response->getProfilePicture()) {
            $OAuthEzUser->setImageLink($response->getProfilePicture());
        }

        return $OAuthEzUser;
    }

    /**
     * Generates a first and last name from the response.
     *
     * @param \HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface $response
     *
     * @return array
     */
    protected function getUsername(UserResponseInterface $response)
    {
        $realName = $response->getRealName();

        if (!empty($realName)) {
            $realName = explode(' ', $realName);

            if (count($realName) >= 2) {
                $firstName = array_shift($realName);
                $lastName = implode(' ', $realName);
            } else {
                $firstName = reset($realName);
                $lastName = reset($realName);
            }
        } else {
            $userEmail = $response->getEmail();

            if (!empty($userEmail)) {
                $emailArray = explode('@', $userEmail);

                $firstName = reset($emailArray);
                $lastName = reset($emailArray);
            } else {
                $firstName = $response->getNickname();
                $lastName = $response->getResourceOwner()->getName();
            }
        }

        return array('firstName' => $firstName, 'lastName' => $lastName);
    }
}
