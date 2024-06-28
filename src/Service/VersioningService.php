<?php

// Création d'un service Symfony pour pouvoir récupérer la version contenu das le champ "accept" de la requête HTTP

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class VersioningService
{
    private $requestStack;
    private $defaultVersion;

    /** 
     * Constructeur permettant de récupérer la quête courant(pour extraite le champ "accept" du header)
     * ainsi que le ParmaeterBagInterface pour récupérer la version par défaut dans le fichier de configuration
     * 
     * @param RequestStack $requestStack
     * @param ParameterBagInterface $params
     */

    public function __construct(RequestStack $requestStack, ParameterBagInterface $params)
    {
        $this->requestStack = $requestStack;
        $this->defaultVersion = $params->get('default_api_version');
    }

    /**
     * Récupération de la version qui a été envoyée dans le header "accept" de la requête HTTP
     *
     * @return string : le numéro de la version. Par défaut, la version retournée est celle définie dans le fichier de configuration servces.yaml : "default_api_version"
     */

    public function getVersion(): string
    {
        $request = $this->defaultVersion;

        $request = $this->requestStack->getCurrentRequest();
        $accept = $request->headers->get('Accept');
        // Récupération du numéro de version dans la chaîne de caractères du accept :
        // exemple: "application/json; text=bidule; version=2.0" => 2.0

        $entete = explode(';', $accept);
        $version = $this->defaultVersion;

        // On parcours toutes les entêtes pour trouver la version
        foreach ($entete as $value) {
            if (strpos($value, 'version') !== false) {
                $version = explode('=', $value);
                $version = $version[1];
                break;
            }
        }

        return $version;
    }
}
