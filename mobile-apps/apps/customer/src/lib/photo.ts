import * as ImageManipulator from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import type { UploadFile } from '@/api/types';

/** Big phone pictures are redrawn smaller before they go up (servers often cap uploads, and mobile data is slow) */
export const MAX_EDGE = 2000;
export const JPEG_QUALITY = 0.82;

/** The size to redraw to: the long edge at most `max`, never bigger than the original */
export function shrunkSize(width: number, height: number, max = MAX_EDGE): { width: number; height: number } | null {
    const scale = Math.min(1, max / Math.max(width, height));

    return scale === 1 ? null : { width: Math.round(width * scale), height: Math.round(height * scale) };
}

/** Opens the photo library and returns one picture, shrunk and as a JPEG; null if the person backs out or says no */
export async function pickPhoto(): Promise<UploadFile | null> {
    const result = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 1, allowsMultipleSelection: false });

    if (result.canceled || !result.assets[0]) {
        return null;
    }

    const asset = result.assets[0];
    const size = shrunkSize(asset.width, asset.height);
    const shrunk = await ImageManipulator.manipulateAsync(asset.uri, size ? [{ resize: size }] : [], { compress: JPEG_QUALITY, format: ImageManipulator.SaveFormat.JPEG });

    return { uri: shrunk.uri, name: `photo-${Date.now()}.jpg`, type: 'image/jpeg' };
}
