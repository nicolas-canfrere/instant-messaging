import { describe, expect, it } from 'vitest';
import { declaredTypeFor } from './declaredType';

/** `File` de test : le contenu importe peu, seuls le nom et le type comptent. */
function fileNamed(name: string, type: string): File {
  return new File(['x'], name, { type });
}

describe('declaredTypeFor', () => {
  it("deduit text/markdown d'un .md meme quand le systeme ne connait pas le type", () => {
    // C'est le cas le plus frequent : aucune entree `.md` dans la base MIME
    // du systeme, donc `file.type` vaut la chaine vide. Se fier a lui
    // enverrait un content_type vide, que le backend refuse en 422.
    expect(declaredTypeFor(fileNamed('notes.md', ''))).toBe('text/markdown');
  });

  it("ignore le type du systeme quand l'extension est plus fiable", () => {
    // Avec Excel installe, un .csv sort en application/vnd.ms-excel.
    expect(declaredTypeFor(fileNamed('donnees.csv', 'application/vnd.ms-excel'))).toBe('text/csv');
  });

  it('reconnait .txt et .pdf', () => {
    expect(declaredTypeFor(fileNamed('notes.txt', 'text/plain'))).toBe('text/plain');
    expect(declaredTypeFor(fileNamed('rapport.pdf', 'application/pdf'))).toBe('application/pdf');
  });

  it("se rabat sur file.type pour les images, dont aucun systeme ne se trompe", () => {
    expect(declaredTypeFor(fileNamed('photo.PNG', 'image/png'))).toBe('image/png');
    expect(declaredTypeFor(fileNamed('anim.gif', 'image/gif'))).toBe('image/gif');
  });

  it("refuse ce qui sort de l'allowlist", () => {
    expect(declaredTypeFor(fileNamed('archive.zip', 'application/zip'))).toBeNull();
    expect(declaredTypeFor(fileNamed('setup.exe', 'application/x-msdownload'))).toBeNull();
  });

  it('refuse un dossier glisse, qui arrive sans type', () => {
    // Un dossier apparait dans DataTransfer.files avec un type vide et une
    // taille arbitraire. C'est un cas que l'utilisateur produira.
    expect(declaredTypeFor(fileNamed('Documents', ''))).toBeNull();
  });
});
