<?php

namespace AppBundle\Security;

use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\FacebookUser;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use AppBundle\Entity\User;

class FacebookAuthenticator extends AbstractGuardAuthenticator
{
    
    private $appId;
    private $appSecret;
    private $router;
    private $facebookProvider;
    private $container;
    
    public function __construct(ContainerInterface $container, $appId, $appSecret, RouterInterface $router)
    {
        $this->container = $container;
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        $this->router = $router;
    }
    
    public function getCredentials(Request $request)
    {
        
        if ($request->getPathInfo() != '/connect/facebook-check') {
            // skip authentication unless we're on this URL!
            return null;
        }
        if ($code = $request->query->get('code')) {
            return $code;
        }
        
        // If this far, then something went wrong
        // TODO - Read the error, error_code, error_description, error_reason query params
        // http://localhost:8000/connect/facebook-check?error=access_denied&error_code=200&error_description=Permissions+error&error_reason=user_denied&state=S2fKgHJSZSJM0Qs2fhKL6USZP50KSBHc#_=_
        // throw CustomAuthenticationException::createWithSafeMessage(
        //     'There was an error getting access from Facebook. Please try again.'
        // );
        
    }
    
    public function getUser($authorizationCode, UserProviderInterface $userProvider)
    {
        
        $provider = $this->getFacebookOAuthProvider();
        
        try {
            
            // the credentials are really the access token
            $accessToken = $provider->getAccessToken(
                'authorization_code',
                ['code' => $authorizationCode]
            );
            
        } catch(IdentityProviderException $ex) 
        {
            
            $response = $e->getResponseBody();
            $errorCode = $response['error']['code'];
            $message = $response['error']['message'];
            //var_dump($response);
            //TODO throw a custom error to handle ???
            
        }
        
        $fbUser = $provider->getResourceOwner($accessToken);
        $email = $fbUser->getEmail();

        $em = $this->container            
            ->get('doctrine')
            ->getManager();
        
        $user = $em
            ->getRepository('AppBundle:User')
            ->findOneBy(['email' => $email]);
            
        //If there is no user, we need to create one
        if(!$user)
        {
            
            $user = new User();
            $user->setUsername($email);
            $user->setEmail($email);
            
            //Set to unencoded password. 
            //Since passwords are encode when checked, users should not be able to login using it
            $user->setPassword('FACEBOOK LOGIN');
            
            //Make sure that a user has at least the role of ROLE_USER when created
	        $roles = $user->getRoles();
	        $user->setRoles($roles);

        }
        
        $user->setFacebookId($fbUser->getId());
        $em->persist($user);
        $em->flush();

        return $user;
            
    }
    
    public function checkCredentials($credentials, UserInterface $user)
    {
        // return true to cause authentication success
        return true;
    }
    
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        // If something goes wrong
        $request->getSession()->set(Security::AUTHENTICATION_ERROR, $exception);
        return new RedirectResponse($this->router->generate('login_action'));
    }
    
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {

        $targetPath = $request->getSession()->get('_security.' . $providerKey . '.target_path');
        
        if (!$targetPath) {
            $targetPath = $this->router->generate('homepage');
        }
        
        return new RedirectResponse($targetPath);
        
    }
    
    public function supportsRememberMe()
    {
        return true;
    }
    
    /**
     * Starts the authentication scheme.
     *
     * @param Request $request The request that resulted in an AuthenticationException
     * @param AuthenticationException $authException The exception that started the authentication process
     *
     * @return Response
     */
    public function start(Request $request, AuthenticationException $authException = null)
    {
        $authUrl = $this->getFacebookOAuthProvider()->getAuthorizationUrl([
            // these are actually the default copes
            'scopes' => ['public_profile', 'email'],
        ]);
        return new RedirectResponse($authUrl);
    }
    
    /**
     * @return Facebook
     */
    private function getFacebookOAuthProvider()
    {
        
        if ($this->facebookProvider === null) {
            
            $this->facebookProvider = new Facebook(array(
                'clientId' => $this->appId,
                'clientSecret' => $this->appSecret,
                'redirectUri' => $this->router->generate(
                    'connect_facebook_check',
                    [],
                    RouterInterface::ABSOLUTE_URL
                ),
                'graphApiVersion' => 'v2.3',
            ));
            
        }
        
        return $this->facebookProvider;
        
    }
    
}