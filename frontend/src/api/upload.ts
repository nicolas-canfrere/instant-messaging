/**
 * Le PUT des octets vit HORS du client HTTP typé, et ce n'est pas un oubli.
 *
 * Cette requête ne vise pas notre API : elle vise le stockage objet, par une
 * URL signée. Trois différences qui interdisent de la faire passer par
 * `client.ts` :
 *  - elle ne doit PAS porter nos cookies de session (`credentials: 'omit'`) —
 *    les envoyer à un tiers serait une fuite ;
 *  - elle ne rend pas de Problem Details : en cas d'échec, il n'y a qu'un
 *    statut et du XML S3 ;
 *  - son `Content-Type` est SIGNÉ. Envoyer autre chose que ce que le serveur a
 *    signé fait échouer la requête avec `SignatureDoesNotMatch`, pas avec une
 *    erreur de validation.
 *
 * Les octets ne passent jamais par notre backend : c'est tout l'intérêt. Une
 * photo de 8 Mo va du navigateur au stockage en une requête, sans occuper un
 * process PHP pendant tout le transfert.
 */
export async function putBytes(uploadUrl: string, file: File): Promise<void> {
  const response = await fetch(uploadUrl, {
    method: 'PUT',
    credentials: 'omit',
    // Doit être EXACTEMENT le type déclaré à la pré-signature.
    headers: { 'Content-Type': file.type },
    body: file,
  });

  if (!response.ok) {
    throw new Error(`Le transfert a échoué (${response.status}).`);
  }
}
