UPDATE media
SET mime_type = 'image/jpeg'
WHERE media_type = 'photo' AND mime_type IS NULL;
