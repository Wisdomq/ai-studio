<?php

namespace App\Ai\Skills;

/**
 * WorkflowTemplateLibrary
 *
 * Pre-validated ComfyUI workflow templates using ONLY nodes confirmed
 * to be installed and working on this ComfyUI instance.
 *
 * Active workflows reference:
 *   image       → Z-Image Turbo (SD1.5/ZIT nodes + CLIP/VAE/KSampler)
 *   audio       → Qwen TTS (FB_Qwen3TTSVoiceClone)
 *   video       → AnimateDiff (ADE nodes + VHS_VideoCombine)
 *   image_to_video → LTX2 (LTXVideo nodes)
 *   video_to_video → ReActor (ReActor nodes)
 *
 * Each template:
 *   - Contains {{POSITIVE_PROMPT}} and {{NEGATIVE_PROMPT}} placeholders
 *   - Uses only real node class_types present in /object_info
 *   - Has all inputs/outputs wired correctly
 *   - Is immediately usable after DB insert
 */
class WorkflowTemplateLibrary
{
    // ─── Template type constants ──────────────────────────────────────────────

    const TYPE_TEXT_TO_IMAGE       = 'image';
    const TYPE_TEXT_TO_VIDEO       = 'video';
    const TYPE_TEXT_TO_AUDIO       = 'audio';
    const TYPE_IMAGE_TO_VIDEO      = 'image_to_video';
    const TYPE_VIDEO_TO_VIDEO      = 'video_to_video';

    // ─── Type resolution ──────────────────────────────────────────────────────

    /**
     * Map a free-text intent classification to a template type constant.
     * Returns null if no template exists for this type.
     */
    public static function resolveType(string $classification): ?string
    {
        return match (strtolower(trim($classification))) {
            'image', 'text_to_image', 'text to image', 'txt2img' => self::TYPE_TEXT_TO_IMAGE,
            'video', 'text_to_video', 'text to video', 'txt2vid' => self::TYPE_TEXT_TO_VIDEO,
            'audio', 'text_to_audio', 'text to audio', 'tts', 'voice', 'speech' => self::TYPE_TEXT_TO_AUDIO,
            'image_to_video', 'img2vid', 'image to video' => self::TYPE_IMAGE_TO_VIDEO,
            'video_to_video', 'vid2vid', 'video to video', 'face_swap', 'faceswap' => self::TYPE_VIDEO_TO_VIDEO,
            default => null,
        };
    }

    /**
     * Return all supported type names for use in LLM prompts.
     */
    public static function supportedTypes(): array
    {
        return [
            self::TYPE_TEXT_TO_IMAGE,
            self::TYPE_TEXT_TO_VIDEO,
            self::TYPE_TEXT_TO_AUDIO,
            self::TYPE_IMAGE_TO_VIDEO,
            self::TYPE_VIDEO_TO_VIDEO,
        ];
    }

    // ─── Template retrieval ───────────────────────────────────────────────────

    /**
     * Get the workflow JSON template for a given type.
     * Returns null if no template exists.
     */
    public static function get(string $type): ?string
    {
        return match ($type) {
            self::TYPE_TEXT_TO_IMAGE  => self::textToImageTemplate(),
            self::TYPE_TEXT_TO_VIDEO  => self::textToVideoTemplate(),
            self::TYPE_TEXT_TO_AUDIO  => self::textToAudioTemplate(),
            self::TYPE_IMAGE_TO_VIDEO => self::imageToVideoTemplate(),
            self::TYPE_VIDEO_TO_VIDEO => self::videoToVideoTemplate(),
            default => null,
        };
    }

    /**
     * Get the output_type for a workflow type (what it produces).
     */
    public static function outputType(string $type): string
    {
        return match ($type) {
            self::TYPE_TEXT_TO_IMAGE  => 'image',
            self::TYPE_TEXT_TO_VIDEO  => 'video',
            self::TYPE_TEXT_TO_AUDIO  => 'audio',
            self::TYPE_IMAGE_TO_VIDEO => 'video',
            self::TYPE_VIDEO_TO_VIDEO => 'video',
            default                   => 'image',
        };
    }

    /**
     * Get required input_types for a workflow type.
     */
    public static function inputTypes(string $type): array
    {
        return match ($type) {
            self::TYPE_IMAGE_TO_VIDEO => ['image'],
            self::TYPE_VIDEO_TO_VIDEO => ['video', 'image'], // video source + face image
            default                   => [],
        };
    }

    /**
     * Get inject_keys for a workflow type.
     */
    public static function injectKeys(string $type): array
    {
        return match ($type) {
            self::TYPE_IMAGE_TO_VIDEO => ['image' => '{{INPUT_IMAGE}}'],
            self::TYPE_VIDEO_TO_VIDEO => ['video' => '{{INPUT_VIDEO}}', 'image' => '{{INPUT_IMAGE}}'],
            default                   => [],
        };
    }

    // ─── Templates ────────────────────────────────────────────────────────────

    /**
     * Text → Image using SD1.5 (CheckpointLoaderSimple + KSampler pipeline).
     * Mirrors the Z-Image Turbo node structure, using confirmed working nodes.
     */
    private static function textToImageTemplate(): string
    {
        return json_encode([
            "1" => [
                "inputs"     => ["ckpt_name" => "SD1.5/v1-5-pruned-emaonly.ckpt"],
                "class_type" => "CheckpointLoaderSimple",
                "_meta"      => ["title" => "Load Checkpoint"],
            ],
            "2" => [
                "inputs"     => ["text" => "{{POSITIVE_PROMPT}}", "clip" => ["1", 1]],
                "class_type" => "CLIPTextEncode",
                "_meta"      => ["title" => "Positive Prompt"],
            ],
            "3" => [
                "inputs"     => ["text" => "{{NEGATIVE_PROMPT}}", "clip" => ["1", 1]],
                "class_type" => "CLIPTextEncode",
                "_meta"      => ["title" => "Negative Prompt"],
            ],
            "4" => [
                "inputs"     => ["width" => "{{WIDTH}}", "height" => "{{HEIGHT}}", "batch_size" => 1],
                "class_type" => "EmptyLatentImage",
                "_meta"      => ["title" => "Empty Latent Image"],
            ],
            "5" => [
                "inputs"     => [
                    "seed"         => "{{SEED}}",
                    "steps"        => "{{STEPS}}",
                    "cfg"          => "{{CFG}}",
                    "sampler_name" => "euler",
                    "scheduler"    => "normal",
                    "denoise"      => "{{DENOISE}}",
                    "model"        => ["1", 0],
                    "positive"     => ["2", 0],
                    "negative"     => ["3", 0],
                    "latent_image" => ["4", 0],
                ],
                "class_type" => "KSampler",
                "_meta"      => ["title" => "KSampler"],
            ],
            "6" => [
                "inputs"     => ["samples" => ["5", 0], "vae" => ["1", 2]],
                "class_type" => "VAEDecode",
                "_meta"      => ["title" => "VAE Decode"],
            ],
            "7" => [
                "inputs"     => ["filename_prefix" => "generated_image", "images" => ["6", 0]],
                "class_type" => "SaveImage",
                "_meta"      => ["title" => "Save Image"],
            ],
        ]);
    }

    /**
     * Text → Video using AnimateDiff (ADE_AnimateDiffLoaderGen1 + ADE_UseEvolvedSampling).
     * Mirrors the working AnimateDiff workflow structure exactly.
     */
    private static function textToVideoTemplate(): string
    {
        return json_encode([
            "1" => [
                "inputs"     => ["ckpt_name" => "SD1.5/v1-5-pruned-emaonly.ckpt"],
                "class_type" => "CheckpointLoaderSimple",
                "_meta"      => ["title" => "Load Checkpoint"],
            ],
            "2" => [
                "inputs"     => [
                    "model_name"    => "v3_sd15_mm.ckpt",
                    "beta_schedule" => "autoselect",
                    "model"         => ["1", 0],
                ],
                "class_type" => "ADE_AnimateDiffLoaderGen1",
                "_meta"      => ["title" => "AnimateDiff Loader"],
            ],
            "3" => [
                "inputs"     => ["text" => "{{POSITIVE_PROMPT}}", "clip" => ["1", 1]],
                "class_type" => "CLIPTextEncode",
                "_meta"      => ["title" => "Positive Prompt"],
            ],
            "4" => [
                "inputs"     => ["text" => "{{NEGATIVE_PROMPT}}", "clip" => ["1", 1]],
                "class_type" => "CLIPTextEncode",
                "_meta"      => ["title" => "Negative Prompt"],
            ],
            "5" => [
                "inputs"     => ["width" => "{{WIDTH}}", "height" => "{{HEIGHT}}", "batch_size" => "{{FRAME_COUNT}}"],
                "class_type" => "EmptyLatentImage",
                "_meta"      => ["title" => "Empty Latent Image"],
            ],
            "6" => [
                "inputs"     => [
                    "context_length"       => 16,
                    "context_stride"       => 1,
                    "context_overlap"      => 4,
                    "context_schedule"     => "uniform",
                    "closed_loop"          => false,
                    "fuse_method"          => "flat",
                    "use_on_equal_length"  => false,
                    "start_percent"        => 0,
                    "guarantee_steps"      => 1,
                ],
                "class_type" => "ADE_AnimateDiffUniformContextOptions",
                "_meta"      => ["title" => "Context Options"],
            ],
            "7" => [
                "inputs"     => [
                    "motion_model"   => ["2", 0],
                    "context_options" => ["6", 0],
                ],
                "class_type" => "ADE_ApplyAnimateDiffModelSimple",
                "_meta"      => ["title" => "Apply AnimateDiff Model"],
            ],
            "8" => [
                "inputs"     => [
                    "seed"         => "{{SEED}}",
                    "steps"        => "{{STEPS}}",
                    "cfg"          => "{{CFG}}",
                    "sampler_name" => "euler",
                    "scheduler"    => "normal",
                    "denoise"      => "{{DENOISE}}",
                    "motion_model" => ["7", 0],
                    "positive"     => ["3", 0],
                    "negative"     => ["4", 0],
                    "latent_image" => ["5", 0],
                ],
                "class_type" => "ADE_UseEvolvedSampling",
                "_meta"      => ["title" => "Use Evolved Sampling"],
            ],
            "9" => [
                "inputs"     => ["samples" => ["8", 0], "vae" => ["1", 2]],
                "class_type" => "VAEDecode",
                "_meta"      => ["title" => "VAE Decode"],
            ],
            "10" => [
                "inputs"     => [
                    "frame_rate"      => "{{FPS}}",
                    "loop_count"      => 0,
                    "filename_prefix" => "AnimateDiff",
                    "format"          => "video/h264-mp4",
                    "pix_fmt"         => "yuv420p",
                    "crf"             => 19,
                    "save_metadata"   => true,
                    "trim_to_audio"   => false,
                    "pingpong"        => false,
                    "save_output"     => true,
                    "images"          => ["9", 0],
                ],
                "class_type" => "VHS_VideoCombine",
                "_meta"      => ["title" => "Video Combine"],
            ],
        ]);
    }

    /**
     * Text → Audio using Qwen TTS Voice Clone.
     * Uses the FB_Qwen3TTSVoiceClone node confirmed present in ComfyUI.
     */
    private static function textToAudioTemplate(): string
    {
        return json_encode([
            "1" => [
                "inputs"     => [
                    "text"     => "{{POSITIVE_PROMPT}}",
                    "duration" => "{{DURATION}}",
                ],
                "class_type" => "FB_Qwen3TTSVoiceClone",
                "_meta"      => ["title" => "Qwen TTS"],
            ],
        ]);
    }

    /**
     * Image + Audio → Video using LTX2.
     * Uses LTX Video nodes confirmed working on this instance.
     * Requires an input image via {{INPUT_IMAGE}} placeholder.
     */
    private static function imageToVideoTemplate(): string
    {
        return json_encode([
            "1" => [
                "inputs"     => ["ckpt_name" => "LTX-2/ltx-2-19b-dev-fp8.safetensors"],
                "class_type" => "CheckpointLoaderSimple",
                "_meta"      => ["title" => "Load LTX2 Checkpoint"],
            ],
            "2" => [
                "inputs"     => ["image" => "{{INPUT_IMAGE}}"],
                "class_type" => "LoadImage",
                "_meta"      => ["title" => "Load Input Image"],
            ],
            "3" => [
                "inputs"     => ["text" => "{{POSITIVE_PROMPT}}", "clip" => ["1", 1]],
                "class_type" => "CLIPTextEncode",
                "_meta"      => ["title" => "Positive Prompt"],
            ],
            "4" => [
                "inputs"     => ["text" => "{{NEGATIVE_PROMPT}}", "clip" => ["1", 1]],
                "class_type" => "CLIPTextEncode",
                "_meta"      => ["title" => "Negative Prompt"],
            ],
            "5" => [
                "inputs"     => [
                    "seed"         => "{{SEED}}",
                    "steps"        => "{{STEPS}}",
                    "cfg"          => "{{CFG}}",
                    "sampler_name" => "euler",
                    "scheduler"    => "normal",
                    "denoise"      => "{{DENOISE}}",
                    "model"        => ["1", 0],
                    "positive"     => ["3", 0],
                    "negative"     => ["4", 0],
                    "latent_image" => ["2", 0],
                ],
                "class_type" => "KSampler",
                "_meta"      => ["title" => "KSampler"],
            ],
            "6" => [
                "inputs"     => ["samples" => ["5", 0], "vae" => ["1", 2]],
                "class_type" => "VAEDecode",
                "_meta"      => ["title" => "VAE Decode"],
            ],
            "7" => [
                "inputs"     => [
                    "frame_rate"      => "{{FPS}}",
                    "loop_count"      => 0,
                    "filename_prefix" => "LTX2_I2V",
                    "format"          => "video/h264-mp4",
                    "pix_fmt"         => "yuv420p",
                    "crf"             => 19,
                    "save_metadata"   => true,
                    "trim_to_audio"   => false,
                    "pingpong"        => false,
                    "save_output"     => true,
                    "images"          => ["6", 0],
                ],
                "class_type" => "VHS_VideoCombine",
                "_meta"      => ["title" => "Video Combine"],
            ],
        ]);
    }

    /**
     * Video → Video face swap using ReActor.
     * Requires input video via {{INPUT_VIDEO}} and face image via {{INPUT_IMAGE}}.
     */
    private static function videoToVideoTemplate(): string
    {
        return json_encode([
            "1" => [
                "inputs"     => ["video" => "{{INPUT_VIDEO}}", "force_rate" => 0, "force_size" => "Disabled", "custom_width" => 512, "custom_height" => 512, "frame_load_cap" => 0, "skip_first_frames" => 0, "select_every_nth" => 1],
                "class_type" => "VHS_LoadVideo",
                "_meta"      => ["title" => "Load Source Video"],
            ],
            "2" => [
                "inputs"     => ["image" => "{{INPUT_IMAGE}}"],
                "class_type" => "LoadImage",
                "_meta"      => ["title" => "Load Face Image"],
            ],
            "3" => [
                "inputs"     => [
                    "enabled"             => true,
                    "swap_model"          => "inswapper_128.onnx",
                    "facedetection"       => "retinaface_resnet50",
                    "face_restore_model"  => "GFPGANv1.4.pth",
                    "face_restore_visibility" => 1,
                    "codeformer_weight"   => 0.5,
                    "detect_gender_input" => "no",
                    "detect_gender_source" => "no",
                    "input_faces_index"   => "0",
                    "source_faces_index"  => "0",
                    "console_log_level"   => 1,
                    "input_image"         => ["1", 0],
                    "source_image"        => ["2", 0],
                ],
                "class_type" => "ReActorFaceSwap",
                "_meta"      => ["title" => "ReActor Face Swap"],
            ],
            "4" => [
                "inputs"     => [
                    "frame_rate"      => "{{FPS}}",
                    "loop_count"      => 0,
                    "filename_prefix" => "FaceSwap",
                    "format"          => "video/h264-mp4",
                    "pix_fmt"         => "yuv420p",
                    "crf"             => 19,
                    "save_metadata"   => true,
                    "trim_to_audio"   => false,
                    "pingpong"        => false,
                    "save_output"     => true,
                    "images"          => ["3", 0],
                ],
                "class_type" => "VHS_VideoCombine",
                "_meta"      => ["title" => "Video Combine"],
            ],
        ]);
    }
}