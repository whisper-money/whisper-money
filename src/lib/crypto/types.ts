export interface KDFParams {
  algo: "PBKDF2";
  hash: "SHA-256";
  iterations: number;
  salt_kek: string;
}

export interface WrappedDEK {
  alg: "AES-GCM";
  iv_wrap: string;
  ciphertext: string;
}

export interface EncryptedBlob {
  alg: "AES-GCM";
  iv: string;
  ciphertext: string;
  aad?: string;
}

export interface ServerProfile {
  kdf: KDFParams;
  wrapped_dek: WrappedDEK;
  crypto_version: number;
}

export interface RegisterPayload {
  email: string;
  kdf: KDFParams;
  wrapped_dek: WrappedDEK;
  crypto_version: number;
}

export interface LoginResponse {
  profile: ServerProfile;
}

export interface SessionKeys {
  dek: CryptoKey;
  kek: CryptoKey;
}

