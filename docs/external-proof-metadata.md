# Proof uploads: architecture revised

The former external/Firebase metadata endpoint has been replaced by authenticated multipart uploads to Laravel private storage. See ../../LARAVEL_PRIVATE_PROOFS_SETUP.md for the current contract and setup.

The historical migration and external proof references are preserved. They are not publicly exposed or automatically downloaded/migrated. New uploads use proof_path and proof_size; proof_storage_path and proof_url remain legacy-only fields. Do not restore the old metadata uploader.
