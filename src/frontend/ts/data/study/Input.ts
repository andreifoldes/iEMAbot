import { DataStructure } from "../DataStructure";

export type InputResponseType =
	"ambient_light" |
	"app_usage" |
	"battery_level" |
	"binary" |
	"bluetooth_devices" |
	"compass" |
	"countdown" |
	"date" |
	"duration" |
	"dynamic_input" |
	"file_upload" |
	"image" |
	"likert" |
	"list_multiple" |
	"list_single" |
	"location" |
	"noise_level" |
	"number" |
	"photo" |
	"record_audio" |
	"record_keystrokes" |
	"share" |
	"text" |
	"text_input" |
	"time" |
	"va_scale" |
	"video" |
	"webapp"

export type InputMediaTypes = "image" | "audio" | "keystrokes"

export class Input extends DataStructure {
	public responseType = this.primitive<InputResponseType>("responseType", "text_input")
	public name = this.primitive<string>("name", "input")
	public required = this.primitive<boolean>("required", false)
	public optional = this.primitive<boolean>("optional", false)
	public random = this.primitive<boolean>("random", false)
	public likertSteps = this.primitive<number>("likertSteps", 5)
	public numberHasDecimal = this.primitive<boolean>("numberHasDecimal", false)
	public asDropDown = this.primitive<boolean>("asDropDown", true)
	public forceInt = this.primitive<boolean>("forceInt", false)
	public packageId = this.primitive<string>("packageId", "")
	public timeoutSec = this.primitive<number>("timeoutSec", 0)
	public playSound = this.primitive<boolean>("playSound", false)
	public showValue = this.primitive<boolean>("showValue", false)
	public maxValue = this.primitive<number>("maxValue", 0)
	// Web/PWA client only. "record_audio": max recording length in seconds (0 = default 300).
	// Registered here so designer saves round-trip the value even without a UI option.
	public maxLength = this.primitive<number>("maxLength", 0)
	// Web/PWA client only. "record_keystrokes": soft minimum typed length in characters —
	// folded into the writing nudge, never blocks saving (0 = no length target).
	public minLength = this.primitive<number>("minLength", 0)
	public resolution = this.primitive<number>("resolution", 0)
	public quality = this.primitive<number>("quality", 100)
	public relevance = this.primitive<string>("relevance", "")
	public vertical = this.primitive<boolean>("vertical", false)
	public textScript = this.primitive<string>("textScript", "")
	public other = this.primitive<boolean>("other", false)
	public useCustomStart = this.primitive<boolean>("useCustomStart", false)
	public customStart = this.primitive<number>("customStart", 0)

	public defaultValue = this.translatable("defaultValue", "")
	public text = this.translatable("text", "")
	// Optional explanatory text shown beneath the main question, de-emphasised (smaller,
	// regular weight, muted) so it reads as a helper rather than part of the question.
	// Web/PWA client only — native apps ignore it. Empty for every item by default.
	public description = this.translatable("description", "")
	// Accessibility content supplied by the researcher (web/PWA client only; native apps
	// ignore it). imageAlt: text alternative for an "image" input. screenReaderLabel:
	// announced by screen readers in place of the question text, e.g. to describe a scale
	// or slider. spokenText: read aloud by a "Listen" button on the question.
	public imageAlt = this.translatable("imageAlt", "")
	public screenReaderLabel = this.translatable("screenReaderLabel", "")
	public spokenText = this.translatable("spokenText", "")
	public url = this.translatable("url", "")
	// "webapp" type: instructions shown on the launch card (the item `text` is the title,
	// `url` is the launch URL). Empty for every other input type.
	public webappDescription = this.translatable("webappDescription", "")
	public leftSideLabel = this.translatable("leftSideLabel", "")
	public rightSideLabel = this.translatable("rightSideLabel", "")
	public listChoices = this.translatableArray("listChoices")

	public subInputs = this.objectArray("subInputs", Input)


	public getMediaType(): InputMediaTypes | null {
		switch (this.responseType.get()) {
			case "file_upload":
			case "photo":
				return "image";
			case "record_audio":
				return "audio";
			case "record_keystrokes":
				return "keystrokes";
			default:
				return null;
		}
	}
}