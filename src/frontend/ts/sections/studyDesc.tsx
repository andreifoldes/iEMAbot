import {SectionContent} from "../site/SectionContent";
import m, {Vnode} from "mithril";
import {Lang} from "../singletons/Lang";
import {ObservableLangChooser} from "../components/ObservableLangChooser";
import {BindObservable} from "../components/BindObservable";
import {RichText} from "../components/RichText";
import {RegexTextInput} from "../components/RegexTextInput";
import {SectionData} from "../site/SectionData";
import {TRIANGLIFY_PALETTES, generateProceduralCover} from "../helpers/ProceduralCover";

export class Content extends SectionContent {
	public static preLoad(sectionData: SectionData): Promise<any>[] {
		return [sectionData.getStudyPromise()]
	}
	public title(): string {
		return Lang.get("study_description")
	}

	private selectedPalette: string = "Emerald (default)"

	private generateTrianglifyArtwork(): void {
		const palette = TRIANGLIFY_PALETTES[this.selectedPalette] ?? TRIANGLIFY_PALETTES["Emerald (default)"]
		// Random seed so each click yields a fresh variation the admin can shuffle through
		this.getStudyOrThrow().studyArtwork.set(generateProceduralCover({ palette }))
		m.redraw()
	}

	private handleArtworkUpload(e: Event): void {
		const input = e.target as HTMLInputElement
		const file = input.files?.[0]
		if (!file) return
		const reader = new FileReader()
		reader.onload = () => {
			this.getStudyOrThrow().studyArtwork.set(reader.result as string)
			m.redraw()
		}
		reader.readAsDataURL(file)
	}

	public getView(): Vnode<any, any> {
		const study = this.getStudyOrThrow()
		return <div>
			<label>
				<small>{Lang.getWithColon("title")}</small>
				<input type="text" {...BindObservable(study.title)} />
				{ObservableLangChooser(study)}
			</label>

			<label>
				<small>{Lang.getWithColon("study_tag")}</small>
				<input type="text" {...BindObservable(study.studyTag)} />
				{ObservableLangChooser(study)}
			</label>

			{
				RegexTextInput(
					Lang.getWithColon("contactEmail"),
					study.contactEmail,
					/^[\w\-.]+@([\w-]+\.)+[\w-]{2,}$/,
					Lang.get("validator_warning_email"))
			}

			<div class="fakeLabel spacingTop line">
				<small>Study artwork ({Lang.get("can_be_left_empty")}):</small>
				{study.studyArtwork.get() &&
					<div style="margin:8px 0;">
						<img
							src={study.studyArtwork.get()}
							alt="Study artwork preview"
							style="width:100%;max-width:400px;height:100px;border-radius:8px;object-fit:cover;display:block;"
						/>
					</div>
				}
				<div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:8px;">
					<select
						style="flex:1;min-width:160px;"
						onchange={(e: Event) => {
							this.selectedPalette = (e.target as HTMLSelectElement).value
							m.redraw()
						}}
					>
						{Object.keys(TRIANGLIFY_PALETTES).map(name =>
							<option value={name} selected={name === this.selectedPalette}>{name}</option>
						)}
					</select>
					<button type="button" onclick={this.generateTrianglifyArtwork.bind(this)}>
						Generate artwork
					</button>
					<small style="color:#666;">or</small>
					<input type="file" accept="image/*" onchange={this.handleArtworkUpload.bind(this)} />
					{study.studyArtwork.get() &&
						<button type="button" onclick={() => { study.studyArtwork.set(""); m.redraw() }}>Remove</button>
					}
				</div>
			</div>

			<div class="fakeLabel spacingTop line">
				<small>{Lang.getWithColon("description")}</small>
				{RichText(study.studyDescription)}
				{ObservableLangChooser(study)}
			</div>

			<label class="spacingTop line">
				<small>{Lang.get("informed_consent")} ({Lang.get("can_be_left_empty")}):</small>
				<textarea {...BindObservable(study.informedConsentForm)}></textarea>
				{ObservableLangChooser(this.getStudyOrThrow())}
			</label>

			<div class="fakeLabel spacingTop line">
				<small>{Lang.getWithColon("postInstallInstructions")}</small>
				{RichText(study.postInstallInstructions)}
				{ObservableLangChooser(this.getStudyOrThrow())}
			</div>

			{study.enableTutorialMode.get() &&
				<div class="fakeLabel spacingTop line">
					<h3 class="center">{Lang.get("tutorial")}</h3>
					<label class="line">
						<small>{Lang.getWithColon("tutorial_offer_prompt")} ({Lang.get("can_be_left_empty")})</small>
						<textarea {...BindObservable(study.tutorialOffer)}></textarea>
						{ObservableLangChooser(this.getStudyOrThrow())}
					</label>
					<label class="line spacingTop">
						<small>{Lang.getWithColon("tutorial_intro_text")} ({Lang.get("can_be_left_empty")})</small>
						<textarea {...BindObservable(study.tutorialIntro)}></textarea>
						{ObservableLangChooser(this.getStudyOrThrow())}
					</label>
				</div>
			}

			<div class="fakeLabel spacingTop line">
				<small>{Lang.get("faqs")} ({Lang.get("can_be_left_empty")}):</small>
				{RichText(study.faq)}
				{ObservableLangChooser(this.getStudyOrThrow())}
			</div>

			<div class="fakeLabel spacingTop line">
				<small>{Lang.getWithColon("webInstallInstructions")}</small>
				{RichText(study.webInstallInstructions)}
				{ObservableLangChooser(this.getStudyOrThrow())}
			</div>

			<div class="fakeLabel spacingTop line">
				<small>{Lang.getWithColon("chooseUsernameInstructions")}</small>
				{RichText(study.chooseUsernameInstructions)}
				{ObservableLangChooser(this.getStudyOrThrow())}
			</div>

			<div class="fakeLabel spacingTop line">
				<small>{Lang.getWithColon("webQuestionnaireCompletedInstructions")}</small>
				{RichText(study.webQuestionnaireCompletedInstructions)}
				{ObservableLangChooser(this.getStudyOrThrow())}
			</div>

			<div class="fakeLabel spacingTop line">
				<small>{Lang.getWithColon("post_study_note")}</small>
				{RichText(study.postStudyNote)}
				{ObservableLangChooser(this.getStudyOrThrow())}
			</div>
		</div>
	}
}