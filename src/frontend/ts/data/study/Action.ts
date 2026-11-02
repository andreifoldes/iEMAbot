import {DataStructure} from "../DataStructure";

export class Action extends DataStructure {
	public type								= this.primitive<number>(		"type",					1) //is Invitation
	public timeout							= this.primitive<number>(		"timeout",					0)
	public reminder_count					= this.primitive<number>(		"reminder_count",			0)
	public reminder_delay_minu				= this.primitive<number>(		"reminder_delay_minu",		5)
	
	public msgText							= this.translatable(			"msgText",					"")
	// iEMAbot: notification title for this trigger on every channel; empty = the questionnaire title.
	public msgTitle							= this.translatable(			"msgTitle",					"")
}