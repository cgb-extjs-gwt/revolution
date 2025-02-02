<?php
/**
 * Sources English lexicon topic
 *
 * @language en
 * @package modx
 * @subpackage lexicon
 */
$_lang['access'] = 'Autorisations d\'accès';
$_lang['base_path'] = 'Chemin de base';
$_lang['base_path_relative'] = 'Chemin de base relatif ?';
$_lang['base_url'] = 'URL de base';
$_lang['base_url_relative'] = 'URL de base relative ?';
$_lang['minimum_role'] = 'Rôle minimum';
$_lang['path_options'] = 'Options de chemin';
$_lang['policy'] = 'Règle';
$_lang['source'] = 'Media Source';
$_lang['source_access_add'] = 'Ajouter un groupe d\'utilisateurs';
$_lang['source_access_remove'] = 'Supprimer l\'accès';
$_lang['source_access_remove_confirm'] = 'Êtes-vous sûr de vouloir supprimer l\'accès à cette source pour ce groupe d\'utilisateurs ?';
$_lang['source_access_update'] = 'Modifier l\'accès';
$_lang['source_description_desc'] = 'Courte description du Media Source.';
$_lang['source_err_ae_name'] = 'Un Media Source portant ce nom existe déjà! Veuillez indiquer un nouveau nom.';
$_lang['source_err_nf'] = 'Media Source non trouvé!';
$_lang['source_err_init'] = 'Impossible d\'initaliser la Media Source "[[+source]]" !';
$_lang['source_err_nfs'] = 'Aucun Media Source avec l\'id « [[+id]] » ne peut être trouvé.';
$_lang['source_err_ns'] = 'Veuillez indiquer le Media Source.';
$_lang['source_err_ns_name'] = 'Veuillez indiquer un nom pour le Media Source.';
$_lang['source_name_desc'] = 'Nom du Media Source.';
$_lang['source_properties.intro_msg'] = 'Gérez les propriétés de cette Source.';
$_lang['source_remove_confirm'] = 'Êtes-vous sûr de vouloir supprimer cette Media Source ? Ceci peut créer des erreurs à chaque TV assignée à cette source.';
$_lang['source_remove_multiple_confirm'] = 'Êtes-vous sûr de vouloir supprimer ces Media Sources ? Ceci peut créer des erreurs à chaque TVs étant assignée à ces sources.';
$_lang['source_type'] = 'Type de source';
$_lang['source_type_desc'] = 'Le type, ou driver, du Media Source. La Source utilisera ce driver pour s\'y connecter et récupérer ses données. Par exemple : Système de fichiers récupérera les fichiers depuis le système de fichiers. S3 récupèrera les fichiers depuis un « S3 bucket » (service Amazon).';
$_lang['source_type.file'] = 'Système de fichiers';
$_lang['source_type.file_desc'] = 'Une source basée sur le système de fichiers, affichant les fichiers de votre serveur.';
$_lang['source_type.s3'] = 'Amazon S3';
$_lang['source_type.s3_desc'] = 'Affiche les fichiers d\'un « Amazon S3 bucket ».';
$_lang['source_type.ftp'] = 'Protocole de transfert de fichiers';
$_lang['source_type.ftp_desc'] = 'Accède à un serveur distant FTP.';
$_lang['source_types'] = 'Type de source';
$_lang['source_types.intro_msg'] = 'Liste de tous les types de Media Source installés dans cette instance de MODX.';
$_lang['source.access.intro_msg'] = 'Vous pouvez restreindre l\'accès a un Media Source pour certains groupes d\'utilisateurs et appliquer des règles affectant ces groupes d\'utilisateurs. Un Media Source sans groupe d\'utilisateurs lui étant rattaché est accessible par tous les utilisateurs du manager.';
$_lang['sources'] = 'Media Sources';
$_lang['sources.intro_msg'] = 'Gérez vos Media Sources.';
$_lang['user_group'] = 'Groupe d\'utilisateurs';

/* file source type */
$_lang['allowedFileTypes'] = 'Types de fichiers autorisés';
$_lang['prop_file.allowedFileTypes_desc'] = 'Si défini, n\'affiche que les fichiers utilisants les extensions indiquées. Veuillez indiquer ces extensions séparées par des virgules, sans spécifier le . (point)';
$_lang['basePath'] = 'chemin de base';
$_lang['prop_file.basePath_desc'] = 'Le chemin vers lequel faire pointer la Source, par exemple : assets/images/<br>Le chemin peut dépendre du paramètre "basePathRelative"';
$_lang['basePathRelative'] = 'chemin de base relatif';
$_lang['prop_file.basePathRelative_desc'] = 'Si le chemin de base ci-dessus est relatif au répertoire d\'installation de base de MODX, sélectionnez Oui.';
$_lang['baseUrl'] = 'URL de base';
$_lang['prop_file.baseUrl_desc'] = 'L\'URL à partir de laquelle cette source peut être accédée, par exemple : assets/images/<br>Le chemin peut dépendre du paramètre "baseUrlRelative"';
$_lang['baseUrlPrependCheckSlash'] = 'Verification de l\'azjout de slash en tête de l\'URL de base';
$_lang['prop_file.baseUrlPrependCheckSlash_desc'] = 'Activé, MODX préfixera seulement l\'URL de base si aucun slash (/) n\'est présent au début de l\'URL lors du rendu de la TV. Utile pour définir une valeur de TV en dehors de l\'URL de base.';
$_lang['baseUrlRelative'] = 'Url de base relative';
$_lang['prop_file.baseUrlRelative_desc'] = 'Si l\'URL de base ci-dessus est relative à l\'URL de base d\'installation de MODX, sélectionnez Oui.';
$_lang['imageExtensions'] = 'Extensions de l\'image';
$_lang['prop_file.imageExtensions_desc'] = 'Une liste d\'extension de fichiers d\'images, séparées par des virgules. MODX essaiera de créer des miniatures des fichiers portant ces extensions.';
$_lang['skipFiles'] = 'ignorer les fichiers';
$_lang['prop_file.skipFiles_desc'] = 'Une liste d\'extensions de fichiers séparées par des virgules. MODX masquera les fichiers et dossiers qui correspondants.';
$_lang['skipExtensions'] = 'skipExtensions';
$_lang['prop_file.skipExtensions'] = 'Une liste d\'extensions séparées par des virgules. MODX n\'affichera pas les fichiers qui correspondent à l\'une de ces extensions.';
$_lang['thumbnailQuality'] = 'qualite de la vignette';
$_lang['prop_file.thumbnailQuality_desc'] = 'La qualité de rendu des miniatures, dans une fourchette de 0 à 100.';
$_lang['thumbnailType'] = 'type de vignette';
$_lang['prop_file.thumbnailType_desc'] = 'Le type d\'image à utiliser pour afficher les miniatures.';
$_lang['prop_file.visibility_desc'] = 'Visibilité par défaut des nouveaux fichiers et dossiers.';
$_lang['no_move_folder'] = 'Le pilote de la Source de média ne supporte pas le déplacement de dossiers actuellement.';

/* s3 source type */
$_lang['bucket'] = 'Seau';
$_lang['prop_s3.bucket_desc'] = 'Le S3 Bucket depuis lequel charger les données.';
$_lang['prop_s3.key_desc'] = 'Clé d\'identification du Amazon bucket.';
$_lang['prop_s3.imageExtensions_desc'] = 'Une liste d\'extension de fichiers d\'images, séparées par des virgules. MODX essaiera de créer des miniatures des fichiers portant ces extensions.';
$_lang['prop_s3.secret_key_desc'] = 'Clé secrète d\'identification du Amazon bucket.';
$_lang['prop_s3.skipFiles_desc'] = 'Une liste d\'extensions de fichiers séparées par des virgules. MODX masquera les fichiers et dossiers qui correspondants.';
$_lang['prop_s3.thumbnailQuality_desc'] = 'La qualité de rendu des miniatures, dans une fourchette de 0 à 100.';
$_lang['prop_s3.thumbnailType_desc'] = 'Le type d\'image à utiliser pour afficher les miniatures.';
$_lang['prop_s3.url_desc'] = 'L\'URL de l\'instance du Amazon S3.';
$_lang['prop_s3.endpoint_desc'] = 'URL de terminaison ("endpoint") compatible S3, par exemple "https://s3.<region>.example.com". Examinez la documentation de votre fournisseur compatible S3 pour l\'emplacement du "endpoint". Laissez vide pour Amazon S3';
$_lang['prop_s3.region_desc'] = 'Région du conteneur. Exemple : us-west-1';
$_lang['prop_s3.prefix_desc'] = 'Préfixe facultatif du chemin d’accès /dossier';
$_lang['prop_s3.no_check_bucket_desc'] = 'If set, don\'t attempt to check the bucket exists. It can be needed if the access key you are using does not have bucket creation/list permissions.';
$_lang['s3_no_move_folder'] = 'Le driver S3 ne supporte pas, pour le moment, le déplacement de dossiers.';

/* ftp source type */
$_lang['prop_ftp.host_desc'] = 'Nom du serveur ou adresse IP';
$_lang['prop_ftp.username_desc'] = 'Nom d’utilisateur pour l’authentification. Peut être « anonyme ».';
$_lang['prop_ftp.password_desc'] = 'Mot de passe utilisateur. Laissez vide pour utilisateur anonyme.';
$_lang['prop_ftp.url_desc'] = 'Si ce FTP est a une URL publique, vous pouvez entrer son adresse publique http ici. Cela permettra également les aperçus d’image dans le navigateur multimédia.';
$_lang['prop_ftp.port_desc'] = 'Port du serveur, par défaut 21.';
$_lang['prop_ftp.root_desc'] = 'Le dossier racine, il sera ouvert après connexion';
$_lang['prop_ftp.passive_desc'] = 'Activer ou désactiver le mode ftp passif';
$_lang['prop_ftp.ssl_desc'] = 'Activer ou désactiver la connexion ssl';
$_lang['prop_ftp.timeout_desc'] = 'Délai d’attente pour la connexion en secondes.';

/* file type */
$_lang['PNG'] = 'PNG';
$_lang['JPG'] = 'JPG';
$_lang['GIF'] = 'GIF';
$_lang['WebP'] = 'WebP';
